@echo off
REM Kanaxta - ejecuta el extractor (lo usa el Programador de tareas de Windows)
cd /d "%~dp0"
if not exist logs mkdir logs
call .venv\Scripts\activate.bat
python extractor.py >> logs\salida_programada.log 2>&1
