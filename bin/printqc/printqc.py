#!/usr/bin/env python3
"""
printqc - kwaliteitscontrole voor Printhuis printbestanden (Gelato)

Gebruik:
    python printqc.py bestand.png [meer.png ...]
    python printqc.py exports/                 # hele map
    python printqc.py bestand.png --formaat 50x70
    python printqc.py exports/ --rapport rapport/ --json uitslag.json

Exitcodes:  0 = alles PASS   1 = minstens een REVIEW   2 = minstens een FAIL

Afhankelijkheden: pillow, numpy
"""

import argparse
import hashlib
import io
import json
import os
import re
import sys
from dataclasses import dataclass, field, asdict

import numpy as np
from PIL import Image, ImageCms

Image.MAX_IMAGE_PIXELS = None

# ---------------------------------------------------------------- instellingen

FORMATEN = {
    "30x40": (30, 40),
    "40x50": (40, 50),
    "50x70": (50, 70),
    "70x100": (70, 100),
}

DPI_IDEAAL = 300
DPI_MINIMAAL = 200

# Korrel, per kanaal gemeten. Zie KALIBRATIE onderaan dit bestand:
# deze getallen zijn voorlopig en moeten tegen een fysieke print geijkt worden.
KORREL_SCHOON = 1.5
KORREL_GRENS = 3.0

# Lokale detailuitval (het defect dat globale metingen missen)
BLOK = 128            # blokgrootte in px
UITVAL_OMGEVING = 200  # omgeving moet minstens dit detailniveau hebben
UITVAL_RATIO = 0.35    # blok onder dit deel van zijn omgeving = verdacht
UITVAL_MAX = 0         # aantal toegestane uitvalblokken voor een PASS

# Tegelnaden
NAAD_SEGMENT = 512     # lengte waarover een naad aaneengesloten moet zijn
NAAD_SPRONG = 2.5      # hoeveel scherper dan de buurkolommen
NAAD_DEKKING = 0.7     # deel van het segment waar die sprong moet voorkomen

STATUS_ORDE = {"PASS": 0, "REVIEW": 1, "FAIL": 2}


# ------------------------------------------------------------------ datamodel

@dataclass
class Bevinding:
    niveau: str   # PASS / REVIEW / FAIL
    check: str
    tekst: str


@dataclass
class Rapport:
    bestand: str
    md5: str = ""
    bevindingen: list = field(default_factory=list)
    meta: dict = field(default_factory=dict)

    def voeg_toe(self, niveau, check, tekst):
        self.bevindingen.append(Bevinding(niveau, check, tekst))

    @property
    def status(self):
        if not self.bevindingen:
            return "PASS"
        return max((b.niveau for b in self.bevindingen), key=lambda n: STATUS_ORDE[n])


# ------------------------------------------------------------------- helpers

def blokmediaan(m, straal=2):
    """Mediaan van de (2r+1)^2 buren, zonder scipy."""
    h, w = m.shape
    pad = np.pad(m, straal, mode="edge")
    stapels = [
        pad[i:i + h, j:j + w]
        for i in range(2 * straal + 1)
        for j in range(2 * straal + 1)
    ]
    return np.median(np.stack(stapels), axis=0)


def laplaciaan(g):
    return (-4 * g[1:-1, 1:-1] + g[:-2, 1:-1] + g[2:, 1:-1]
            + g[1:-1, :-2] + g[1:-1, 2:])


def formaat_uit_naam(naam):
    m = re.search(r"(\d{2,3})x(\d{2,3})", os.path.basename(naam))
    if m:
        sleutel = f"{int(m.group(1))}x{int(m.group(2))}"
        if sleutel in FORMATEN:
            return sleutel
    return None


# --------------------------------------------------------------- de controles

def check_container(im, rap):
    if im.mode != "RGB":
        rap.voeg_toe("FAIL", "modus",
                     f"modus is {im.mode}, moet RGB zijn "
                     f"({'alfakanaal' if 'A' in im.mode else 'onverwacht kanaal'} "
                     f"pakt bij de printer onvoorspelbaar uit)")
    if (im.format or "").upper() != "PNG":
        rap.voeg_toe("FAIL", "formaat", f"formaat is {im.format}, moet PNG zijn")

    icc = im.info.get("icc_profile")
    if not icc:
        rap.voeg_toe("FAIL", "icc", "geen ICC-profiel ingebed; de printer gokt bij de "
                                    "CMYK-conversie (doffe, koele kleuren)")
    else:
        try:
            prof = ImageCms.ImageCmsProfile(io.BytesIO(icc))
            omschrijving = ImageCms.getProfileDescription(prof).strip()
        except Exception as e:
            rap.voeg_toe("REVIEW", "icc", f"ICC-profiel niet leesbaar: {e}")
            omschrijving = "onleesbaar"
        else:
            if "srgb" not in omschrijving.lower():
                rap.voeg_toe("REVIEW", "icc",
                             f"ICC-profiel is '{omschrijving}', verwacht sRGB")
        rap.meta["icc"] = omschrijving

    rap.meta["dpi_tag"] = im.info.get("dpi")


def check_resolutie(im, rap, doelformaat):
    w, h = im.size
    rap.meta["afmetingen"] = [w, h]
    per_formaat = {}
    for naam, (cw, ch) in FORMATEN.items():
        dw, dh = w / (cw / 2.54), h / (ch / 2.54)
        per_formaat[naam] = [round(dw), round(dh)]
    rap.meta["dpi_per_formaat"] = per_formaat

    if doelformaat:
        dw, dh = per_formaat[doelformaat]
        laagste = min(dw, dh)
        if laagste < DPI_MINIMAAL:
            rap.voeg_toe("FAIL", "resolutie",
                         f"{laagste} DPI op {doelformaat} (minimum {DPI_MINIMAAL}); "
                         f"dit formaat niet aanbieden voor dit design")
        elif laagste < DPI_IDEAAL:
            rap.voeg_toe("REVIEW", "resolutie",
                         f"{laagste} DPI op {doelformaat}, onder de ideale "
                         f"{DPI_IDEAAL} maar boven het minimum")

    # welke formaten zijn wel haalbaar
    haalbaar = [n for n, (dw, dh) in per_formaat.items() if min(dw, dh) >= DPI_IDEAAL]
    rap.meta["ideaal_tot"] = haalbaar


def check_korrel(a, rap):
    """Korrel per kanaal. De oude metriek (b.std() over alle assen) mat de
    kleurspreiding tussen R, G en B mee en flagde warme beelden onterecht."""
    H, W, _ = a.shape
    waarden = []
    for y in range(0, H - 64, 96):
        for x in range(0, W - 64, 96):
            b = a[y:y + 64, x:x + 64]
            waarden.append(float(b.std(axis=(0, 1)).mean()))
    waarden = np.array(waarden)
    vlakste = np.sort(waarden)[:50]
    korrel = float(vlakste.mean())
    echt_vlak = int((waarden < 1.0).sum())

    rap.meta["korrel"] = round(korrel, 2)
    rap.meta["vlakke_blokken"] = echt_vlak
    rap.meta["blokken_totaal"] = int(waarden.size)

    if echt_vlak < 20:
        rap.voeg_toe("REVIEW", "korrel",
                     f"maar {echt_vlak} echt vlakke blokken van {waarden.size}; "
                     f"beeld is te detailrijk om korrel betrouwbaar te meten "
                     f"(gemeten: {korrel:.2f}) - beoordeel met het oog")
    elif korrel > KORREL_GRENS:
        rap.voeg_toe("FAIL", "korrel",
                     f"korrel {korrel:.2f} per kanaal (grens {KORREL_GRENS})")
    elif korrel > KORREL_SCHOON:
        rap.voeg_toe("REVIEW", "korrel",
                     f"korrel {korrel:.2f} per kanaal (schoon is <{KORREL_SCHOON})")


def check_detailuitval(g, rap, max_toegestaan=None, ratio=None, omgeving=None):
    """Het defect dat globale scherpte mist: een blok dat zijn detail kwijt is
    terwijl de omgeving wel detailrijk is. Uitgesmeerde tegels, mislukte
    inpaints, upscale-uitval."""
    lap = laplaciaan(g)
    ny, nx = lap.shape[0] // BLOK, lap.shape[1] // BLOK
    m = np.empty((ny, nx))
    for i in range(ny):
        rij = lap[i * BLOK:(i + 1) * BLOK]
        for j in range(nx):
            m[i, j] = rij[:, j * BLOK:(j + 1) * BLOK].var()

    max_toegestaan = UITVAL_MAX if max_toegestaan is None else max_toegestaan
    drempel_ratio = UITVAL_RATIO if ratio is None else ratio
    drempel_omg = UITVAL_OMGEVING if omgeving is None else omgeving

    omg = blokmediaan(m, straal=2)
    verhouding = (m + 1) / (omg + 1)
    masker = (omg > drempel_omg) & (verhouding < drempel_ratio)

    rap.meta["scherpte_globaal"] = round(float(lap.var()))
    rap.meta["uitval_blokken"] = int(masker.sum())

    plekken = []
    for iy, ix in zip(*np.where(masker)):
        plekken.append({
            "x": int(ix * BLOK), "y": int(iy * BLOK),
            "blok": round(float(m[iy, ix])),
            "omgeving": round(float(omg[iy, ix])),
            "ratio": round(float(verhouding[iy, ix]), 3),
        })
    plekken.sort(key=lambda p: p["ratio"])
    rap.meta["uitval_plekken"] = plekken

    if masker.sum() > max_toegestaan:
        ergste = plekken[0]
        rap.voeg_toe("FAIL", "detailuitval",
                     f"{masker.sum()} blokken missen detail dat hun omgeving wel heeft; "
                     f"ergste op {ergste['x']},{ergste['y']} "
                     f"(blok {ergste['blok']} tegen omgeving {ergste['omgeving']})")
    return masker, m


def check_naden(g, rap):
    """Tegelnaden: een rechte grens die over een lange aaneengesloten strook
    scherper is dan zijn directe buurkolommen. Een gemiddelde over de hele
    kolom middelt zo'n lokale naad weg, dus per segment kijken - en eisen dat
    de sprong bij de meeste rijen van dat segment voorkomt, anders vindt hij
    alleen textuur."""
    gevonden = []
    for as_ in ("kolom", "rij"):
        d = (np.abs(np.diff(g, axis=1)) if as_ == "kolom"
             else np.abs(np.diff(g, axis=0)).T)
        L, P = d.shape
        for s in range(max(1, L // NAAD_SEGMENT)):
            lo, hi = s * NAAD_SEGMENT, min((s + 1) * NAAD_SEGMENT, L)
            blok = d[lo:hi]
            pad = np.pad(blok, ((0, 0), (4, 4)), mode="edge")
            buren = np.median(
                np.stack([pad[:, i:i + P] for i in range(9) if i != 4]), axis=0)
            deel = ((blok + 1) / (buren + 1) > NAAD_SPRONG).mean(axis=0)
            for pos in np.where(deel > NAAD_DEKKING)[0]:
                gevonden.append({"as": as_, "positie": int(pos),
                                 "segment": [lo, hi],
                                 "dekking": round(float(deel[pos]), 2)})

    rap.meta["naden"] = gevonden[:50]
    if gevonden:
        plekken = ", ".join(f"{n['as']} {n['positie']}" for n in gevonden[:4])
        rap.voeg_toe("FAIL", "naden",
                     f"{len(gevonden)} rechte grenzen die op een tegelnaad wijzen "
                     f"({plekken}); bekijk de crop voordat je dit accepteert - "
                     f"een echte rechte lijn in het ontwerp kan dit ook geven")
    return gevonden


def check_kleur(a, rap):
    """Informatief. Er is geen juiste waarde: water hoort koel, zon hoort warm."""
    r, g_, b = (float(a[:, :, i].mean()) for i in range(3))
    rap.meta["kleur"] = {"R": round(r), "G": round(g_), "B": round(b),
                         "R_min_B": round(r - b, 1)}
    verz = float(np.percentile(a.max(axis=2) - a.min(axis=2), 99))
    rap.meta["verzadiging_p99"] = round(verz)
    if verz > 200:
        rap.voeg_toe("REVIEW", "kleur",
                     f"zeer hoge verzadiging (p99 {verz:.0f}); kan op print "
                     f"minder levendig uitpakken dan op het scherm")


# ------------------------------------------------------------------ uitsnedes

def schrijf_crops(im, rap, masker, uitvoer, marge=236):
    """Crops op 100% van de verdachte plekken, zodat je ze met eigen ogen ziet."""
    if masker is None or not masker.any():
        return []
    os.makedirs(uitvoer, exist_ok=True)
    stam = os.path.splitext(os.path.basename(rap.bestand))[0][:40]
    paden = []

    # clusters samenvoegen zodat je niet 66 losse crops krijgt
    bezocht = np.zeros_like(masker, dtype=bool)
    clusters = []
    for start in zip(*np.where(masker)):
        if bezocht[start]:
            continue
        stapel, cel = [start], []
        bezocht[start] = True
        while stapel:
            y, x = stapel.pop()
            cel.append((y, x))
            for dy in (-1, 0, 1):
                for dx in (-1, 0, 1):
                    ny_, nx_ = y + dy, x + dx
                    if (0 <= ny_ < masker.shape[0] and 0 <= nx_ < masker.shape[1]
                            and masker[ny_, nx_] and not bezocht[ny_, nx_]):
                        bezocht[ny_, nx_] = True
                        stapel.append((ny_, nx_))
        clusters.append(cel)
    clusters.sort(key=len, reverse=True)

    for n, cel in enumerate(clusters[:8], 1):
        ys = [c[0] for c in cel]
        xs = [c[1] for c in cel]
        x0 = max(0, min(xs) * BLOK - marge)
        y0 = max(0, min(ys) * BLOK - marge)
        x1 = min(im.width, (max(xs) + 1) * BLOK + marge)
        y1 = min(im.height, (max(ys) + 1) * BLOK + marge)
        pad = os.path.join(uitvoer, f"{stam}_uitval{n}_{x0}x{y0}.png")
        im.crop((x0, y0, x1, y1)).save(pad)
        paden.append(pad)

    # overzichtskaart met de plekken rood gemarkeerd
    schaal = 8
    prev = im.resize((im.width // schaal, im.height // schaal), Image.LANCZOS)
    o = np.asarray(prev).astype(np.float32).copy()
    s = BLOK // schaal
    for y, x in zip(*np.where(masker)):
        vak = (slice(y * s, (y + 1) * s), slice(x * s, (x + 1) * s))
        o[vak][..., 0] = np.clip(o[vak][..., 0] * 0.3 + 255 * 0.7, 0, 255)
        o[vak][..., 1] *= 0.3
        o[vak][..., 2] *= 0.3
    kaart = os.path.join(uitvoer, f"{stam}_kaart.png")
    Image.fromarray(o.astype(np.uint8)).save(kaart)
    paden.insert(0, kaart)
    rap.meta["crops"] = paden
    return paden


# ------------------------------------------------------------------ uitvoeren

def keur(pad, doelformaat=None, cropmap=None, uitval_max=None):
    rap = Rapport(bestand=pad)

    with open(pad, "rb") as fh:
        rap.md5 = hashlib.md5(fh.read()).hexdigest()[:12]

    im = Image.open(pad)
    rap.meta["formaat_container"] = im.format
    rap.meta["modus"] = im.mode

    doel = doelformaat or formaat_uit_naam(pad)
    rap.meta["doelformaat"] = doel
    if not doel:
        rap.voeg_toe("REVIEW", "formaat",
                     "geen printformaat in de bestandsnaam en niets opgegeven; "
                     "resolutiecontrole overgeslagen")

    check_container(im, rap)
    check_resolutie(im, rap, doel)

    rgb = im.convert("RGB")
    a = np.asarray(rgb).astype(np.float32)
    g = np.asarray(rgb.convert("L")).astype(np.float32)

    check_korrel(a, rap)
    masker, _ = check_detailuitval(g, rap, max_toegestaan=uitval_max)
    check_naden(g, rap)
    check_kleur(a, rap)

    if cropmap:
        schrijf_crops(rgb, rap, masker, cropmap)

    return rap


KLEUR = {"PASS": "\033[32m", "REVIEW": "\033[33m", "FAIL": "\033[31m"}
RESET = "\033[0m"


def toon(rap, kleuren=True):
    def k(niveau):
        return f"{KLEUR[niveau]}{niveau}{RESET}" if kleuren else niveau

    m = rap.meta
    print(f"\n{'=' * 68}")
    print(f"{os.path.basename(rap.bestand)}")
    print(f"  md5 {rap.md5}   {m.get('modus')}/{m.get('formaat_container')}   "
          f"{m.get('afmetingen', ['?', '?'])[0]}x{m.get('afmetingen', ['?', '?'])[1]}   "
          f"doel {m.get('doelformaat') or '-'}")
    if m.get("dpi_per_formaat") and m.get("doelformaat"):
        dw, dh = m["dpi_per_formaat"][m["doelformaat"]]
        print(f"  {dw}x{dh} DPI op {m['doelformaat']}   "
              f"ideaal tot: {', '.join(m.get('ideaal_tot')) or 'geen'}")
    print(f"  korrel {m.get('korrel')} ({m.get('vlakke_blokken')} vlakke blokken)   "
          f"scherpte {m.get('scherpte_globaal')}   "
          f"uitval {m.get('uitval_blokken')} blokken")

    if rap.bevindingen:
        print()
        for b in sorted(rap.bevindingen, key=lambda x: -STATUS_ORDE[x.niveau]):
            print(f"  [{k(b.niveau)}] {b.check}: {b.tekst}")
    for c in m.get("crops", [])[:9]:
        print(f"        -> {c}")
    print(f"\n  eindoordeel: [{k(rap.status)}]")


def main():
    p = argparse.ArgumentParser(description="Printbestand-QC voor Printhuis")
    p.add_argument("paden", nargs="+", help="PNG-bestanden of mappen")
    p.add_argument("--formaat", choices=list(FORMATEN),
                   help="doelformaat (anders uit de bestandsnaam gehaald)")
    p.add_argument("--rapport", metavar="MAP",
                   help="map voor crops en overzichtskaarten van de defecten")
    p.add_argument("--json", metavar="BESTAND", help="uitslag als json wegschrijven")
    p.add_argument("--uitval-max", type=int, metavar="N",
                   help=f"aantal toegestane uitvalblokken voor een PASS "
                        f"(standaard {UITVAL_MAX}); verhoog dit pas nadat je de "
                        f"crops hebt bekeken en ze legitieme onscherpte blijken")
    p.add_argument("--geen-kleur", action="store_true")
    args = p.parse_args()

    bestanden = []
    for pad in args.paden:
        if os.path.isdir(pad):
            bestanden += sorted(
                os.path.join(pad, n) for n in os.listdir(pad)
                if n.lower().endswith(".png")
            )
        else:
            bestanden.append(pad)

    if not bestanden:
        print("geen PNG-bestanden gevonden", file=sys.stderr)
        return 2

    rapporten = []
    for b in bestanden:
        try:
            r = keur(b, args.formaat, args.rapport, args.uitval_max)
        except Exception as e:
            r = Rapport(bestand=b)
            r.voeg_toe("FAIL", "inlezen", f"kon bestand niet verwerken: {e}")
        rapporten.append(r)
        toon(r, kleuren=not args.geen_kleur)

    if args.json:
        with open(args.json, "w") as fh:
            json.dump([{**asdict(r), "status": r.status} for r in rapporten],
                      fh, indent=2, ensure_ascii=False)

    print(f"\n{'=' * 68}")
    for niveau in ("FAIL", "REVIEW", "PASS"):
        n = sum(1 for r in rapporten if r.status == niveau)
        if n:
            print(f"  {niveau}: {n}")

    return max((STATUS_ORDE[r.status] for r in rapporten), default=0)


if __name__ == "__main__":
    sys.exit(main())


# ------------------------------------------------------------------ KALIBRATIE
#
# HARD, geen ijking nodig. Deze zijn goed of fout, punt:
#     modus, containerformaat, ICC-profiel, resolutie per printformaat.
#
# HEURISTIEK, moet je zelf ijken:
#     KORREL_SCHOON / KORREL_GRENS / UITVAL_OMGEVING / UITVAL_RATIO / UITVAL_MAX
#
# Er bestaat geen norm die digitale blokstatistiek koppelt aan printacceptatie;
# ISO Graininess meet fysieke prints met een densitometer. Deze getallen zijn
# dus mijn schatting, niet de waarheid.
#
# De detailuitval-check vindt ook LEGITIEME onscherpte: scherptediepte in een
# foto, een bewust glad vlak in een illustratie. Op de twee bestanden waarop
# dit script gebouwd is vindt hij 29 en 66 blokken, en niet al die blokken zijn
# defecten. Ga daarom zo te werk:
#
#   1. Draai het script met --rapport op tien bestanden die je goedkeurt.
#   2. Bekijk elke crop. Noteer per bestand hoeveel blokken echte defecten zijn.
#   3. Zijn het bijna allemaal valse alarmen? Verlaag UITVAL_RATIO (strenger,
#      minder treffers) of verhoog UITVAL_OMGEVING (alleen echt detailrijke
#      omgevingen tellen mee).
#   4. Zet UITVAL_MAX daarna op nul en houd hem daar. De check is bedoeld als
#      poort, niet als suggestie.
#
# Zolang stap 1 t/m 3 niet gedaan zijn is dit script een aanwijsstok: het zegt
# waar je moet kijken, niet of het goed is. Dat is nog altijd meer dan de
# globale gemiddelden deden.
