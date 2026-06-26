@echo off
if exist backend\Dockerfile (echo backend Dockerfile EXISTS) else (echo backend Dockerfile MISSING)
if exist frontend\Dockerfile (echo frontend Dockerfile EXISTS) else (echo frontend Dockerfile MISSING)
if exist docker\postgres\Dockerfile (echo postgres Dockerfile EXISTS) else (echo postgres Dockerfile MISSING)
del check_dockerfiles.bat
