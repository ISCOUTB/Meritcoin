FROM python:3.11-slim

WORKDIR /app

# curl es necesario para el healthcheck de Docker Compose
RUN apt-get update && apt-get install -y --no-install-recommends curl \
    && rm -rf /var/lib/apt/lists/*

# Instalar dependencias primero (capa cacheada)
COPY requirements.txt .
RUN pip install --no-cache-dir -r requirements.txt

# Copiar el código
COPY . .

# Puerto que expone el backend
EXPOSE 8000

# Comando por defecto (docker-compose lo sobreescribe con --reload)
CMD ["uvicorn", "app.main:app", "--host", "0.0.0.0", "--port", "8000"]