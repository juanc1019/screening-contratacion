#!/usr/bin/env node

/**
 * Script de configuración automática del proyecto
 * Sistema de Screening de Contratación
 */

const fs = require('fs');
const path = require('path');

// Colores para terminal
const colors = {
    green: '\x1b[32m',
    yellow: '\x1b[33m',
    red: '\x1b[31m',
    blue: '\x1b[34m',
    reset: '\x1b[0m',
    bold: '\x1b[1m'
};

function log(message, color = 'reset') {
    console.log(`${colors[color]}${message}${colors.reset}`);
}

// Estructura de directorios
const directories = [
    // Backend
    'backend',
    'backend/config',
    'backend/classes',
    'backend/api',
    'backend/utils',
    'backend/workers',
    
    // Frontend
    'frontend',
    'frontend/css',
    'frontend/js',
    'frontend/components',
    
    // Scrapers
    'scrapers',
    'scrapers/government',
    'scrapers/financial',
    'scrapers/judicial',
    'scrapers/database',
    'scrapers/media',
    'scrapers/shared',
    
    // Database
    'database',
    'database/migrations',
    'database/seeds',
    'database/backups',
    
    // Uploads
    'uploads',
    'uploads/excel_files',
    'uploads/local_databases',
    'uploads/search_files',
    
    // Exports
    'exports',
    'exports/reports',
    'exports/results',
    
    // Logs
    'logs',
    
    // Config
    'config',
    
    // Docs
    'docs',
    
    // Tests
    'tests',
    'tests/php',
    'tests/js'
];

// Archivos a crear con contenido
const files = {
    // Composer.json
    'composer.json': `{
    "name": "screening-contratacion/sistema-screening",
    "description": "Sistema Web de Screening de Contratación con PostgreSQL y Scrapers",
    "type": "project",
    "license": "MIT",
    "require": {
        "php": ">=8.0",
        "ext-pdo": "*",
        "ext-pgsql": "*",
        "ext-json": "*",
        "ext-mbstring": "*",
        "ext-curl": "*",
        "ext-zip": "*",
        "phpoffice/phpspreadsheet": "^1.29",
        "monolog/monolog": "^3.0",
        "vlucas/phpdotenv": "^5.5",
        "ramsey/uuid": "^4.7",
        "guzzlehttp/guzzle": "^7.8",
        "symfony/console": "^6.3",
        "league/csv": "^9.10",
        "nesbot/carbon": "^2.72"
    },
    "require-dev": {
        "phpunit/phpunit": "^10.4",
        "squizlabs/php_codesniffer": "^3.7",
        "phpstan/phpstan": "^1.10"
    },
    "autoload": {
        "psr-4": {
            "ScreeningApp\\\\": "backend/classes/",
            "ScreeningApp\\\\Api\\\\": "backend/api/",
            "ScreeningApp\\\\Utils\\\\": "backend/utils/",
            "ScreeningApp\\\\Workers\\\\": "backend/workers/"
        },
        "files": [
            "backend/utils/helpers.php"
        ]
    },
    "scripts": {
        "dev-setup": [
            "@php -r \\"echo 'Configurando entorno...\\\\n';\\"",
            "@php -r \\"if (!file_exists('logs/')) { mkdir('logs/', 0755, true); }\\"",
            "@php -r \\"if (!file_exists('uploads/excel_files/')) { mkdir('uploads/excel_files/', 0755, true); }\\"",
            "@php -r \\"echo 'Directorios creados.\\\\n';\\"" 
        ]
    }
}`,

    // Package.json para scrapers
    'scrapers/package.json': `{
    "name": "screening-scrapers",
    "version": "1.0.0",
    "description": "Scrapers para Sistema de Screening",
    "main": "scraper-manager.js",
    "scripts": {
        "start": "node scraper-manager.js",
        "test": "jest",
        "dev": "nodemon scraper-manager.js"
    },
    "dependencies": {
        "puppeteer": "^21.5.0",
        "axios": "^1.6.0",
        "cheerio": "^1.0.0-rc.12",
        "p-queue": "^7.4.1",
        "dotenv": "^16.3.1"
    },
    "devDependencies": {
        "jest": "^29.7.0",
        "nodemon": "^3.0.1"
    }
}`,

    // .env.example
    '.env.example': `# CONFIGURACIÓN SISTEMA SCREENING
APP_ENV=development
APP_DEBUG=true
APP_NAME="Sistema Screening Contratación"
APP_URL=http://localhost

# BASE DE DATOS POSTGRESQL
DB_CONNECTION=pgsql
DB_HOST=localhost
DB_PORT=5432
DB_DATABASE=screening_contratacion
DB_USERNAME=postgres
DB_PASSWORD=tu_password_aqui

# CONFIGURACIÓN DE BÚSQUEDAS
MAX_BATCH_SIZE=500
MAX_CONCURRENT_SEARCHES=5
MAX_CONCURRENT_SCRAPERS=3
SEARCH_TIMEOUT_SECONDS=30
DEFAULT_SIMILARITY_THRESHOLD=70.0

# CONFIGURACIÓN DE ARCHIVOS
MAX_FILE_SIZE_MB=50
ALLOWED_EXCEL_EXTENSIONS=xlsx,xls,csv
MAX_EXCEL_ROWS=10000

# LOGGING
LOG_LEVEL=INFO
LOG_FILE=logs/application.log
SCRAPER_LOG_FILE=logs/scrapers.log

# SCRAPERS
SCRAPER_RETRY_ATTEMPTS=3
SCRAPER_TIMEOUT_MS=30000
PUPPETEER_HEADLESS=true`,

    // .gitignore
    '.gitignore': `# Variables de entorno
.env
.env.local

# Dependencias
/vendor/
node_modules/
composer.lock
package-lock.json

# Archivos subidos
uploads/
!uploads/.gitkeep

# Logs
logs/
!logs/.gitkeep
*.log

# Cache
cache/
*.cache

# Backups
database/backups/
*.sql.gz

# Sistema operativo
.DS_Store
Thumbs.db

# IDEs
.vscode/
.idea/
*.swp

# Temporales
temp/
tmp/`,

    // gitkeep files para mantener directorios vacíos
    'uploads/.gitkeep': '',
    'uploads/excel_files/.gitkeep': '',
    'uploads/local_databases/.gitkeep': '',
    'uploads/search_files/.gitkeep': '',
    'exports/.gitkeep': '',
    'exports/reports/.gitkeep': '',
    'exports/results/.gitkeep': '',
    'logs/.gitkeep': '',
    'database/backups/.gitkeep': '',

    // Script de setup para PHP
    'backend/workers/db_setup.php': `<?php
/**
 * Script de verificación y setup de base de datos
 */

require_once __DIR__ . '/../../vendor/autoload.php';

use Dotenv\\Dotenv;

// Cargar variables de entorno
if (file_exists(__DIR__ . '/../../.env')) {
    $dotenv = Dotenv::createImmutable(__DIR__ . '/../..');
    $dotenv->load();
}

try {
    $dsn = sprintf(
        'pgsql:host=%s;port=%s;dbname=%s',
        $_ENV['DB_HOST'] ?? 'localhost',
        $_ENV['DB_PORT'] ?? '5432',
        $_ENV['DB_DATABASE'] ?? 'screening_contratacion'
    );
    
    $pdo = new PDO(
        $dsn,
        $_ENV['DB_USERNAME'] ?? 'postgres',
        $_ENV['DB_PASSWORD'] ?? '',
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
    );
    
    echo "✅ Conexión a PostgreSQL exitosa\\n";
    
    // Verificar extensiones
    $extensions = ['uuid-ossp', 'fuzzystrmatch', 'pg_trgm'];
    foreach ($extensions as $ext) {
        try {
            $pdo->exec("CREATE EXTENSION IF NOT EXISTS \\"{$ext}\\"");
            echo "✅ Extensión {$ext} habilitada\\n";
        } catch (Exception $e) {
            echo "⚠️  No se pudo habilitar {$ext}: " . $e->getMessage() . "\\n";
        }
    }
    
    echo "🎉 Setup de base de datos completado\\n";
    
} catch (Exception $e) {
    echo "❌ Error de conexión: " . $e->getMessage() . "\\n";
    echo "💡 Verifica las credenciales en .env\\n";
    exit(1);
}`,

    // Script de instalación
    'install.php': `<?php
/**
 * Script de instalación automatizada
 */

echo "🚀 Iniciando instalación del Sistema de Screening\\n\\n";

// Verificar PHP
if (version_compare(PHP_VERSION, '8.0.0') < 0) {
    die("❌ Se requiere PHP 8.0 o superior. Versión actual: " . PHP_VERSION . "\\n");
}
echo "✅ PHP " . PHP_VERSION . " detectado\\n";

// Verificar extensiones requeridas
$requiredExtensions = ['pdo', 'pgsql', 'mbstring', 'curl', 'zip', 'json'];
foreach ($requiredExtensions as $ext) {
    if (!extension_loaded($ext)) {
        die("❌ Extensión PHP requerida no encontrada: {$ext}\\n");
    }
}
echo "✅ Todas las extensiones PHP requeridas están disponibles\\n";

// Verificar Composer
if (!file_exists('composer.json')) {
    die("❌ composer.json no encontrado\\n");
}

echo "📦 Instalando dependencias PHP...\\n";
system('composer install --no-dev --optimize-autoloader');

// Crear .env si no existe
if (!file_exists('.env') && file_exists('.env.example')) {
    copy('.env.example', '.env');
    echo "✅ Archivo .env creado desde .env.example\\n";
    echo "⚠️  IMPORTANTE: Edita .env con tus credenciales de PostgreSQL\\n";
}

// Verificar directorios con permisos
$directories = ['uploads', 'logs', 'exports'];
foreach ($directories as $dir) {
    if (!is_dir($dir)) {
        mkdir($dir, 0755, true);
    }
    if (!is_writable($dir)) {
        echo "⚠️  Directorio {$dir} no tiene permisos de escritura\\n";
    }
}

echo "\\n🎉 Instalación completada!\\n";
echo "📋 Próximos pasos:\\n";
echo "   1. Editar .env con credenciales de PostgreSQL\\n";
echo "   2. Ejecutar: psql -d screening_contratacion -f database/schema.sql\\n";
echo "   3. Probar: php backend/workers/db_setup.php\\n";
echo "   4. Instalar scrapers: cd scrapers && npm install\\n";`,

    // README básico
    'README.md': `# Sistema de Screening de Contratación

## Instalación Rápida

1. **Ejecutar script de setup:**
   \`\`\`bash
   node setup-project.js
   \`\`\`

2. **Instalar dependencias:**
   \`\`\`bash
   php install.php
   \`\`\`

3. **Configurar base de datos:**
   \`\`\`bash
   # Editar .env con credenciales PostgreSQL
   psql -d screening_contratacion -f database/schema.sql
   \`\`\`

4. **Verificar instalación:**
   \`\`\`bash
   php backend/workers/db_setup.php
   \`\`\`

5. **Instalar scrapers:**
   \`\`\`bash
   cd scrapers && npm install
   \`\`\`

## Estructura Creada

- ✅ Backend PHP con clases y APIs
- ✅ Frontend con Bootstrap 5
- ✅ 22 Scrapers Node.js organizados
- ✅ Base de datos PostgreSQL optimizada
- ✅ Sistema de logs y configuración

## Próximos Pasos

Ejecutar en XAMPP y acceder a:
\`http://localhost/screening-contratacion/frontend/\`

Para más detalles, ver documentación en \`docs/\``
};

// Función para crear directorios
function createDirectories() {
    log('\n📁 Creando estructura de directorios...', 'blue');
    
    directories.forEach(dir => {
        const dirPath = path.join(process.cwd(), dir);
        if (!fs.existsSync(dirPath)) {
            fs.mkdirSync(dirPath, { recursive: true });
            log(`   ✅ ${dir}`, 'green');
        } else {
            log(`   ⚠️  ${dir} ya existe`, 'yellow');
        }
    });
}

// Función para crear archivos
function createFiles() {
    log('\n📄 Creando archivos de configuración...', 'blue');
    
    Object.entries(files).forEach(([filePath, content]) => {
        const fullPath = path.join(process.cwd(), filePath);
        const dir = path.dirname(fullPath);
        
        // Crear directorio padre si no existe
        if (!fs.existsSync(dir)) {
            fs.mkdirSync(dir, { recursive: true });
        }
        
        if (!fs.existsSync(fullPath)) {
            fs.writeFileSync(fullPath, content);
            log(`   ✅ ${filePath}`, 'green');
        } else {
            log(`   ⚠️  ${filePath} ya existe`, 'yellow');
        }
    });
}

// Función para establecer permisos (solo en sistemas Unix)
function setPermissions() {
    if (process.platform !== 'win32') {
        log('\n🔒 Configurando permisos...', 'blue');
        
        const writableDirs = ['uploads', 'logs', 'exports', 'database/backups'];
        writableDirs.forEach(dir => {
            const dirPath = path.join(process.cwd(), dir);
            if (fs.existsSync(dirPath)) {
                try {
                    fs.chmodSync(dirPath, 0o755);
                    log(`   ✅ Permisos 755 para ${dir}`, 'green');
                } catch (error) {
                    log(`   ⚠️  No se pudieron establecer permisos para ${dir}`, 'yellow');
                }
            }
        });
    }
}

// Función para mostrar resumen
function showSummary() {
    log('\n🎉 ¡Estructura del proyecto creada exitosamente!', 'green');
    log('\n📋 Próximos pasos:', 'bold');
    log('   1. Ejecutar: php install.php', 'blue');
    log('   2. Editar .env con credenciales de PostgreSQL', 'blue');
    log('   3. Crear base de datos: createdb screening_contratacion', 'blue');
    log('   4. Ejecutar schema: psql -d screening_contratacion -f database/schema.sql', 'blue');
    log('   5. Verificar: php backend/workers/db_setup.php', 'blue');
    log('   6. Instalar scrapers: cd scrapers && npm install', 'blue');
    
    log('\n📊 Estadísticas:', 'bold');
    log(`   📁 Directorios creados: ${directories.length}`, 'green');
    log(`   📄 Archivos creados: ${Object.keys(files).length}`, 'green');
    log(`   🛠️  Lista para desarrollo en XAMPP`, 'green');
    
    log('\n🌐 Acceso:', 'bold');
    log('   Frontend: http://localhost/screening-contratacion/frontend/', 'blue');
    log('   Backend API: http://localhost/screening-contratacion/backend/api/', 'blue');
}

// Función principal
function main() {
    try {
        log('🚀 SETUP - Sistema de Screening de Contratación', 'bold');
        log('===============================================', 'blue');
        
        // Verificar que estamos en un directorio apropiado
        const currentDir = path.basename(process.cwd());
        if (currentDir !== 'screening-contratacion') {
            log('\n⚠️  Recomendación: ejecutar desde directorio "screening-contratacion"', 'yellow');
            log('   mkdir screening-contratacion && cd screening-contratacion', 'yellow');
        }
        
        createDirectories();
        createFiles();
        setPermissions();
        showSummary();
        
    } catch (error) {
        log(`\n❌ Error durante el setup: ${error.message}`, 'red');
        log('💡 Verifica permisos y vuelve a intentar', 'yellow');
        process.exit(1);
    }
}

// Ejecutar si es llamado directamente
if (require.main === module) {
    main();
}

module.exports = { main, createDirectories, createFiles };