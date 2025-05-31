# 🚀 Sistema de Screening de Contratación

Sistema web completo para realizar búsquedas automatizadas de personas y empresas en bases de datos locales y 22 sitios externos, con búsquedas por similitud usando algoritmo Levenshtein y procesamiento inteligente de archivos Excel.

## 📋 Características Principales

- **✅ Procesamiento Inteligente de Excel:** Extrae automáticamente Identificación y Nombre de cualquier estructura de archivo
- **🔍 Búsquedas por Similitud:** Algoritmo Levenshtein con umbrales configurables (50-100%)
- **🌐 22 Scrapers Externos:** Sitios gubernamentales, financieros, judiciales, medios y bases de datos
- **⚡ Búsquedas Duales:** Masivas por lotes + Individuales en tiempo real
- **📊 Monitoreo en Tiempo Real:** Progreso, notificaciones y estadísticas en pantalla
- **🛡️ Control de Carga:** Límites configurables para no saturar servidores
- **🗄️ PostgreSQL Optimizado:** Base de datos robusta con índices especializados

## 🛠️ Stack Tecnológico

### Backend
- **PHP 8.0+** con PDO
- **PostgreSQL 17** con extensiones Levenshtein y pg_trgm
- **Composer** para gestión de dependencias
- **Monolog** para logging avanzado

### Frontend
- **HTML5 + CSS3 + Bootstrap 5**
- **JavaScript Vanilla** con AJAX
- **Interfaz responsiva** y moderna

### Scrapers
- **Node.js 18+** con Puppeteer
- **Axios** para APIs simples
- **Sistema de colas** y control de rate limiting

## 🚀 Instalación Rápida

### 1. Prerrequisitos
```bash
# Windows 11 (como mencionaste)
- XAMPP con PHP 8.0+
- PostgreSQL 17
- Node.js 18+
- Composer
- Git
```

### 2. Clonar y Configurar
```bash
# Clonar repositorio
git clone [tu-repositorio] screening-contratacion
cd screening-contratacion

# Instalar dependencias PHP
composer install

# Configurar entorno (automáticamente copia .env.example a .env)
composer run-script dev-setup
```

### 3. Configurar Base de Datos
```bash
# Editar .env con tus credenciales de PostgreSQL
cp .env.example .env
# Editar: DB_HOST, DB_PORT, DB_DATABASE, DB_USERNAME, DB_PASSWORD

# Crear base de datos
createdb screening_contratacion

# Ejecutar schema
psql -d screening_contratacion -f database/schema.sql
```

### 4. Configurar Scrapers
```bash
cd scrapers
npm install
cd ..
```

### 5. Verificar Instalación
```bash
# Verificar conexión a BD
php backend/workers/db_setup.php

# Iniciar XAMPP y acceder a:
http://localhost/screening-contratacion/frontend/
```

## 📁 Estructura del Proyecto

```
screening-contratacion/
├── 📁 backend/               # Lógica del servidor PHP
│   ├── 📁 config/           # Configuraciones
│   ├── 📁 classes/          # Clases principales
│   ├── 📁 api/              # Endpoints REST
│   ├── 📁 utils/            # Utilidades
│   └── 📁 workers/          # Procesadores de tareas
├── 📁 frontend/             # Interfaz de usuario
│   ├── 📁 css/              # Estilos
│   ├── 📁 js/               # JavaScript
│   └── *.html               # Páginas
├── 📁 scrapers/             # Scrapers Node.js
│   ├── 📁 government/       # Scrapers gubernamentales
│   ├── 📁 financial/        # Scrapers financieros
│   ├── 📁 judicial/         # Scrapers judiciales
│   ├── 📁 media/            # Scrapers de medios
│   └── 📁 shared/           # Utilidades compartidas
├── 📁 database/             # Schema y migraciones
├── 📁 uploads/              # Archivos subidos
├── 📁 exports/              # Reportes generados
└── 📁 logs/                 # Logs del sistema
```

## 🔧 Configuración

### Variables de Entorno Principales (.env)
```bash
# Base de datos
DB_HOST=localhost
DB_PORT=5432
DB_DATABASE=screening_contratacion
DB_USERNAME=postgres
DB_PASSWORD=tu_password

# Límites de procesamiento
MAX_BATCH_SIZE=500
MAX_CONCURRENT_SCRAPERS=3
DEFAULT_SIMILARITY_THRESHOLD=70.0

# Scrapers
SCRAPER_TIMEOUT_MS=30000
SCRAPER_RETRY_ATTEMPTS=3
PUPPETEER_HEADLESS=true
```

### Sitios Incluidos (22 total)

**Gubernamentales (6):**
- DGELU UNAM
- Gobierno México
- Justice.gov (USA)
- DEA
- FBI
- Orden Jurídico Nacional

**Financieros (3):**
- OFAC
- Treasury.gov
- OpenSanctions

**Judiciales (4):**
- Órgano Judicial Panamá
- Fiscalía Colombia
- Procuraduría Colombia
- Rama Judicial

**Bases de Datos (3):**
- ICIJ Offshore Leaks
- SIC Colombia (Registro)
- SIC Colombia (Consultas)

**Medios (5):**
- Google México (link directo)
- Milenio
- La Silla Rota
- Sol Quintana Roo
- 24 Horas

**Otros (1):**
- Transparencia México

## 🎯 Uso del Sistema

### 1. Cargar Base de Datos Local
1. Ve a "Cargar Archivos" → "Base de Datos Local"
2. Sube archivo Excel (cualquier estructura)
3. El sistema automáticamente identifica columnas de ID y Nombre
4. Ignora campos irrelevantes y procesa solo lo necesario

### 2. Búsquedas Masivas
1. Ve a "Búsquedas Masivas"
2. Sube archivo Excel con registros a buscar
3. Selecciona sitios externos y configuración
4. Monitorea progreso en tiempo real
5. Visualiza resultados con porcentajes de similitud

### 3. Búsquedas Individuales
1. Ve a "Búsqueda Individual"
2. Escribe nombre o identificación
3. Selecciona sitios específicos
4. Obtén resultados inmediatos

### 4. Monitoreo
- **Dashboard:** Estadísticas generales del sistema
- **Progreso:** Barras de progreso en tiempo real
- **Notificaciones:** Alertas en pantalla
- **Historial:** Búsquedas anteriores y estadísticas

## 🔍 Algoritmo de Similitud

El sistema usa **Levenshtein Distance** optimizado con PostgreSQL:

```sql
-- Ejemplo de búsqueda por similitud
SELECT * FROM search_local_similarity(
    'JUAN PEREZ GARCIA',    -- Nombre a buscar
    'RFC123456',            -- ID a buscar (opcional)
    70.0,                   -- Umbral mínimo de similitud
    50                      -- Límite de resultados
);
```

**Umbrales recomendados:**
- **90-100%:** Coincidencias exactas o casi exactas
- **80-89%:** Coincidencias muy probables
- **70-79%:** Coincidencias probables (requieren revisión)
- **50-69%:** Coincidencias posibles (requieren verificación manual)

## 📊 Base de Datos

### Tablas Principales
- **`bulk_searches`:** Registros para búsqueda masiva
- **`search_batches`:** Lotes de procesamiento
- **`local_database_records`:** Base de datos local unificada
- **`search_results`:** Resultados de búsquedas locales
- **`external_results`:** Resultados de scrapers externos
- **`notifications`:** Notificaciones del sistema

### Funciones Especializadas
- **`calculate_similarity()`:** Calcula porcentaje de similitud
- **`search_local_similarity()`:** Búsqueda optimizada por similitud
- **`normalize_name()`:** Normaliza nombres para comparaciones

## 🚀 API Endpoints

### Archivos
```http
POST /backend/api/upload.php
Content-Type: multipart/form-data
```

### Búsquedas
```http
# Búsqueda masiva
POST /backend/api/search.php
{
  "type": "batch",
  "batch_id": "uuid",
  "config": {...}
}

# Búsqueda individual
POST /backend/api/search.php
{
  "type": "individual",
  "search_term": "Juan Perez",
  "sites": ["ofac", "fbi"]
}
```

### Progreso
```http
GET /backend/api/progress.php?batch_id=uuid
```

### Resultados
```http
GET /backend/api/results.php?batch_id=uuid&type=summary
```

## ⚡ Optimización y Performance

### Base de Datos
- **Índices GIN:** Para búsquedas de texto rápidas
- **Índices de similitud:** Optimizados para Levenshtein
- **Conexiones pooling:** Reutilización de conexiones
- **Query caching:** Cache de consultas frecuentes

### Scrapers
- **Rate limiting:** Control de velocidad por sitio
- **Reintentos automáticos:** 3 intentos por falla
- **Timeouts configurables:** 30 segundos por defecto
- **Proxy rotation:** Soporte para proxies (opcional)

### Sistema
- **Procesamiento por lotes:** Máximo 500 registros por lote
- **Concurrencia limitada:** Máximo 3 scrapers simultáneos
- **Monitoreo en tiempo real:** Actualizaciones cada segundo
- **Logging completo:** Todas las operaciones registradas

## 🔒 Seguridad

- **Validación de archivos:** Tipos y tamaños permitidos
- **Sanitización de datos:** Limpieza automática de inputs
- **Rate limiting:** Protección contra abuso
- **Logging de seguridad:** Registro de todas las operaciones
- **CORS configurado:** Solo orígenes permitidos

## 🧪 Testing

```bash
# Ejecutar tests PHP
composer test

# Verificar código
composer cs-check

# Análisis estático
composer stan

# Tests de scrapers
cd scrapers && npm test
```

## 📝 Logging

### Niveles de Log
- **DEBUG:** Información detallada de desarrollo
- **INFO:** Operaciones normales del sistema
- **WARNING:** Situaciones que requieren atención
- **ERROR:** Errores que afectan funcionalidad
- **CRITICAL:** Errores críticos del sistema

### Archivos de Log
- `logs/application.log` - Log principal de la aplicación
- `logs/scrapers.log` - Logs específicos de scrapers
- `logs/database.log` - Operaciones de base de datos
- `logs/errors.log` - Solo errores y críticos

## 🐛 Troubleshooting

### Problemas Comunes

**Error de conexión a PostgreSQL:**
```bash
# Verificar que PostgreSQL esté corriendo
pg_ctl status

# Verificar credenciales en .env
cat .env | grep DB_
```

**Scrapers no funcionan:**
```bash
# Verificar Node.js
node --version

# Reinstalar dependencias
cd scrapers && npm install
```

**Archivos Excel no se procesan:**
```bash
# Verificar permisos de directorio
chmod 755 uploads/
chmod 755 uploads/excel_files/

# Verificar extensiones PHP
php -m | grep -E "(pdo|zip|mbstring)"
```

**Performance lenta:**
```bash
# Optimizar base de datos
psql -d screening_contratacion -c "VACUUM ANALYZE;"

# Verificar índices
psql -d screening_contratacion -c "\di"
```

## 🔄 Migración a Producción

### Checklist de Deployment
- [ ] Configurar PostgreSQL en servidor de producción
- [ ] Actualizar variables de entorno (.env)
- [ ] Configurar nginx/apache
- [ ] Instalar certificados SSL
- [ ] Configurar backups automáticos
- [ ] Habilitar monitoreo
- [ ] Configurar logs rotativos
- [ ] Testing completo en producción

### Configuración de Servidor
```nginx
server {
    listen 80;
    server_name tu-dominio.com;
    root /var/www/screening-contratacion/frontend;
    
    location /backend/ {
        alias /var/www/screening-contratacion/backend/;
        location ~ \.php$ {
            fastcgi_pass unix:/var/run/php/php8.0-fpm.sock;
            fastcgi_index index.php;
            include fastcgi_params;
        }
    }
}
```

## 📞 Soporte

### Documentación Adicional
- `docs/INSTALLATION.md` - Guía detallada de instalación
- `docs/API.md` - Documentación completa de API
- `docs/SCRAPERS.md` - Guía de scrapers
- `docs/DEPLOYMENT.md` - Guía de deployment

### Desarrollo
Para contribuir al proyecto:
1. Fork el repositorio
2. Crear rama feature (`git checkout -b feature/nueva-funcionalidad`)
3. Commit cambios (`git commit -am 'Agregar nueva funcionalidad'`)
4. Push a la rama (`git push origin feature/nueva-funcionalidad`)
5. Crear Pull Request

## 📄 Licencia

Este proyecto está bajo la Licencia MIT. Ver archivo `LICENSE` para más detalles.

## 📊 Estadísticas del Proyecto

- **Lenguajes:** PHP, JavaScript, SQL
- **Líneas de código:** ~15,000+
- **Archivos:** ~80+
- **Scrapers:** 22 sitios externos
- **Base de datos:** 9 tablas optimizadas
- **APIs:** 8 endpoints principales

---

**⚡ Sistema desarrollado para búsquedas eficientes y precisas con tecnología moderna y escalable.**