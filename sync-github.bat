@echo off
chcp 65001 >nul
echo ========================================================
echo   DONG BO & DANG DU AN LEN GITHUB TU DONG
echo   Truong Bao Ngoc - Portfolio
echo ========================================================
echo.

where git >nul 2>nul
if %errorlevel% neq 0 (
    echo [LOI] May tinh cua ban chua cai dat Git!
    echo Vui long cai dat Git tai https://git-scm.com/download/win
    echo hoac mo PowerShell chay: winget install --id Git.Git -e --source winget
    echo.
    pause
    exit /b 1
)

if not exist ".git" (
    echo [*] Dang khoi tao Git repository lan dau...
    git init
    git branch -M main
    echo [*] Vui long lien ket repository GitHub bang cach chay:
    echo     git remote add origin https://github.com/TEN_USER/TEN_REPO.git
    echo.
)

echo [*] Dang kiem tra thay doi moi...
git status -s

echo.
set /p commit_msg="Nhap noi dung ghi chu cap nhat (de trong se tu dong lay thoi gian): "
if "%commit_msg%"=="" (
    for /f "tokens=1-4 delims=/ " %%a in ('date /t') do (set mydate=%%c-%%b-%%a)
    for /f "tokens=1-2 delims=: " %%a in ('time /t') do (set mytime=%%a:%%b)
    set commit_msg=Cap nhat du an luc %date% %time%
)

echo.
echo [*] Dang gom file (git add .)...
git add .

echo [*] Dang tao commit...
git commit -m "%commit_msg%"

echo [*] Dang day code len GitHub (git push origin main)...
git push origin main

if %errorlevel% equ 0 (
    echo.
    echo ========================================================
    echo   [THANH CONG] Du an da duoc cap nhat len GitHub!
    echo ========================================================
) else (
    echo.
    echo [CHU Y] Neu day len that bai, hay dam bao ban da lien ket Remote:
    echo   git remote add origin https://github.com/TEN_USER/TEN_REPO.git
    echo hoac kiem tra quyen truy cap tai khoan GitHub cua ban.
)

echo.
pause
