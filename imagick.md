
#### 7a. Download the correct DLL package

1. Go to [mlocati.github.io/articles/php-windows-imagick.html](https://mlocati.github.io/articles/php-windows-imagick.html)
2. Select your configuration:
    - **PHP version:** match your Herd PHP version (e.g. 8.3)
    - **Architecture:** x64
    - **Thread Safety:** nts (non-thread-safe)
3. Download the zip file

> To check your exact config, run `php -i | findstr /C:"Thread Safety" /C:"Architecture" /C:"PHP Version"`.

#### 7b. Place the files

1. Copy **`php_imagick.dll`** into your Herd PHP ext directory:
   ```
   C:\Users\<username>\.config\herd\bin\php83\ext\
   ```
2. Create a new folder for the ImageMagick DLLs:
   ```
   C:\Users\<username>\.config\herd\bin\php83\imagick\
   ```
3. Extract all **other** DLLs from the zip (`CORE_RL_*.dll`, `IM_MOD_RL_*.dll`, etc.) into that `imagick\` folder

> Replace `php83` with your actual PHP version folder (e.g. `php84`).

#### 7c. Add to Windows PATH

1. Open **Settings → System → About → Advanced system settings → Environment Variables**
2. Under **System variables**, edit `Path`
3. Add this entry near the top:
   ```
   C:\Users\<username>\.config\herd\bin\php83\imagick
   ```

#### 7d. Edit php.ini

In Herd, right-click on your PHP version → **Open php.ini directory**. Add at the bottom of `php.ini`:

```ini
[Imagick]
extension="C:\Users\<username>\.config\herd\bin\php83\ext\php_imagick.dll"
```

#### 7e. Restart and verify

Restart your computer (a full restart, not just Herd), then verify:

```bash
php -m | findstr imagick
```

> Do **not** install ImageMagick separately via the MSI installer — it can cause version conflicts with the DLLs.
