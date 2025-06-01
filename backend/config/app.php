<?php

/**
 * Configuración General de la Aplicación
 * Sistema de Screening de Contratación
 */

// Cargar variables de entorno
require_once __DIR__ . '/../../vendor/autoload.php';

use Dotenv\Dotenv;

// Cargar .env si existe
if (file_exists(__DIR__ . '/../../.env')) {
    $dotenv = Dotenv::createImmutable(__DIR__ . '/../..');
    $dotenv->load();
}

return [
    // Información básica de la aplicación
    'name' => $_ENV['APP_NAME'] ?? 'Sistema Screening Contratación',
    'version' => $_ENV['APP_VERSION'] ?? '1.0.0',
    'env' => $_ENV['APP_ENV'] ?? 'production',
    'debug' => filter_var($_ENV['APP_DEBUG'] ?? false, FILTER_VALIDATE_BOOLEAN),
    'url' => $_ENV['APP_URL'] ?? 'http://localhost',
    'timezone' => 'America/Mexico_City',
    'locale' => 'es_MX',

    // Configuración de directorios
    'paths' => [
        'root' => dirname(__DIR__, 2),
        'backend' => dirname(__DIR__),
        'frontend' => dirname(__DIR__, 2) . '/frontend',
        'scrapers' => dirname(__DIR__, 2) . '/scrapers',
        'uploads' => dirname(__DIR__, 2) . '/' . ($_ENV['UPLOAD_DIR'] ?? 'uploads'),
        'exports' => dirname(__DIR__, 2) . '/' . ($_ENV['EXPORT_DIR'] ?? 'exports'),
        'logs' => dirname(__DIR__, 2) . '/' . ($_ENV['LOGS_DIR'] ?? 'logs'),
        'config' => __DIR__,
        'database' => dirname(__DIR__, 2) . '/database',
    ],

    // Configuración de archivos y uploads
    'files' => [
        'max_size_mb' => (int)($_ENV['MAX_FILE_SIZE_MB'] ?? 50),
        'allowed_extensions' => explode(',', $_ENV['ALLOWED_EXCEL_EXTENSIONS'] ?? 'xlsx,xls,csv'),
        'max_excel_rows' => (int)($_ENV['MAX_EXCEL_ROWS'] ?? 10000),
        'excel_files_dir' => $_ENV['EXCEL_FILES_DIR'] ?? 'uploads/excel_files/',
        'local_db_dir' => $_ENV['LOCAL_DB_DIR'] ?? 'uploads/local_databases/',
        'search_files_dir' => $_ENV['SEARCH_FILES_DIR'] ?? 'uploads/search_files/',
    ],

    // Configuración de búsquedas y procesamiento
    'search' => [
        'max_batch_size' => (int)($_ENV['MAX_BATCH_SIZE'] ?? 500),
        'max_concurrent_searches' => (int)($_ENV['MAX_CONCURRENT_SEARCHES'] ?? 5),
        'max_concurrent_scrapers' => (int)($_ENV['MAX_CONCURRENT_SCRAPERS'] ?? 3),
        'search_timeout_seconds' => (int)($_ENV['SEARCH_TIMEOUT_SECONDS'] ?? 30),
        'batch_processing_delay' => (int)($_ENV['BATCH_PROCESSING_DELAY'] ?? 1000),
        'similarity' => [
            'default_threshold' => (float)($_ENV['DEFAULT_SIMILARITY_THRESHOLD'] ?? 70.0),
            'min_threshold' => (float)($_ENV['MIN_SIMILARITY_THRESHOLD'] ?? 50.0),
            'max_threshold' => (float)($_ENV['MAX_SIMILARITY_THRESHOLD'] ?? 100.0),
        ],
    ],

    // Configuración de scrapers
    'scrapers' => [
        'retry_attempts' => (int)($_ENV['SCRAPER_RETRY_ATTEMPTS'] ?? 3),
        'rate_limit_delay' => (int)($_ENV['SCRAPER_RATE_LIMIT_DELAY'] ?? 2000),
        'user_agent' => $_ENV['SCRAPER_USER_AGENT'] ?? 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36',
        'timeout_ms' => (int)($_ENV['SCRAPER_TIMEOUT_MS'] ?? 30000),
        'debug' => filter_var($_ENV['SCRAPER_DEBUG'] ?? false, FILTER_VALIDATE_BOOLEAN),

        // Configuración Node.js
        'node_path' => $_ENV['NODE_PATH'] ?? 'node',
        'npm_path' => $_ENV['NPM_PATH'] ?? 'npm',

        // Configuración Puppeteer
        'puppeteer' => [
            'headless' => filter_var($_ENV['PUPPETEER_HEADLESS'] ?? true, FILTER_VALIDATE_BOOLEAN),
            'timeout' => (int)($_ENV['PUPPETEER_TIMEOUT'] ?? 30000),
            'viewport' => [
                'width' => (int)($_ENV['PUPPETEER_VIEWPORT_WIDTH'] ?? 1920),
                'height' => (int)($_ENV['PUPPETEER_VIEWPORT_HEIGHT'] ?? 1080),
            ],
        ],

        // Configuración de proxy
        'proxy' => [
            'enabled' => filter_var($_ENV['USE_PROXY'] ?? false, FILTER_VALIDATE_BOOLEAN),
            'host' => $_ENV['PROXY_HOST'] ?? '',
            'port' => $_ENV['PROXY_PORT'] ?? '',
            'username' => $_ENV['PROXY_USERNAME'] ?? '',
            'password' => $_ENV['PROXY_PASSWORD'] ?? '',
        ],
    ],

    // Configuración de logging
    'logging' => [
        'level' => $_ENV['LOG_LEVEL'] ?? 'INFO',
        'files' => [
            'application' => $_ENV['LOG_FILE'] ?? 'logs/application.log',
            'scrapers' => $_ENV['SCRAPER_LOG_FILE'] ?? 'logs/scrapers.log',
            'database' => $_ENV['DATABASE_LOG_FILE'] ?? 'logs/database.log',
            'errors' => $_ENV['ERROR_LOG_FILE'] ?? 'logs/errors.log',
        ],
        'rotation' => [
            'max_size_mb' => (int)($_ENV['LOG_MAX_SIZE_MB'] ?? 100),
            'max_files' => (int)($_ENV['LOG_MAX_FILES'] ?? 10),
        ],
    ],

    // Configuración de notificaciones
    'notifications' => [
        'enabled' => filter_var($_ENV['NOTIFICATIONS_ENABLED'] ?? true, FILTER_VALIDATE_BOOLEAN),
        'auto_dismiss_seconds' => (int)($_ENV['NOTIFICATION_AUTO_DISMISS_SECONDS'] ?? 5),
        'max_stack' => (int)($_ENV['NOTIFICATION_MAX_STACK'] ?? 10),
        'progress_update_interval_ms' => (int)($_ENV['PROGRESS_UPDATE_INTERVAL_MS'] ?? 1000),
    ],

    // Configuración de seguridad
    'security' => [
        'app_key' => $_ENV['APP_KEY'] ?? 'tu_clave_secreta_aqui_32_caracteres',
        'jwt_secret' => $_ENV['JWT_SECRET'] ?? 'tu_jwt_secret_aqui',
        'rate_limit' => [
            'enabled' => filter_var($_ENV['RATE_LIMIT_ENABLED'] ?? true, FILTER_VALIDATE_BOOLEAN),
            'requests_per_minute' => (int)($_ENV['RATE_LIMIT_REQUESTS_PER_MINUTE'] ?? 100),
        ],
        'cors' => [
            'allowed_origins' => explode(',', $_ENV['CORS_ALLOWED_ORIGINS'] ?? 'http://localhost,http://127.0.0.1'),
            'allowed_methods' => explode(',', $_ENV['CORS_ALLOWED_METHODS'] ?? 'GET,POST,PUT,DELETE,OPTIONS'),
            'allowed_headers' => explode(',', $_ENV['CORS_ALLOWED_HEADERS'] ?? 'Content-Type,Authorization,X-Requested-With'),
        ],
    ],

    // Configuración de cache
    'cache' => [
        'enabled' => filter_var($_ENV['CACHE_ENABLED'] ?? true, FILTER_VALIDATE_BOOLEAN),
        'ttl_seconds' => (int)($_ENV['CACHE_TTL_SECONDS'] ?? 3600),
        'prefix' => 'screening_app_',
    ],

    // Configuración de APIs externas
    'external_apis' => [
        'ofac' => [
            'enabled' => filter_var($_ENV['OFAC_ENABLED'] ?? true, FILTER_VALIDATE_BOOLEAN),
            'url' => $_ENV['OFAC_URL'] ?? 'https://sanctionssearch.ofac.treas.gov/',
            'api_key' => $_ENV['OFAC_API_KEY'] ?? '',
        ],
        'opensanctions' => [
            'enabled' => filter_var($_ENV['OPENSANCTIONS_ENABLED'] ?? true, FILTER_VALIDATE_BOOLEAN),
            'api_url' => $_ENV['OPENSANCTIONS_API_URL'] ?? 'https://api.opensanctions.org/search/',
            'api_key' => $_ENV['OPENSANCTIONS_API_KEY'] ?? '',
        ],
        'google_mexico' => [
            'enabled' => filter_var($_ENV['GOOGLE_MEXICO_ENABLED'] ?? true, FILTER_VALIDATE_BOOLEAN),
            'search_url' => $_ENV['GOOGLE_SEARCH_URL'] ?? 'https://www.google.com.mx/search?q=',
        ],
    ],

    // URLs de sitios externos configurables
    'sites' => [
        'government' => [
            'dgelu_unam' => $_ENV['DGELU_UNAM_URL'] ?? 'https://www.dgelu.unam.mx/',
            'gobierno_mexico' => $_ENV['GOBIERNO_MEXICO_URL'] ?? 'https://www.gob.mx/',
            'justice_gov' => $_ENV['JUSTICE_GOV_URL'] ?? 'https://www.justice.gov/',
            'dea' => $_ENV['DEA_URL'] ?? 'https://www.dea.gov/',
            'fbi' => $_ENV['FBI_URL'] ?? 'https://www.fbi.gov/',
            'orden_juridico' => $_ENV['ORDEN_JURIDICO_URL'] ?? 'http://www.ordenjuridico.gob.mx/',
        ],
        'financial' => [
            'ofac' => $_ENV['OFAC_SEARCH_URL'] ?? 'https://sanctionssearch.ofac.treas.gov/',
            'treasury' => $_ENV['TREASURY_URL'] ?? 'https://www.treasury.gov/',
            'opensanctions' => $_ENV['OPENSANCTIONS_URL'] ?? 'https://www.opensanctions.org/',
        ],
        'judicial' => [
            'organo_judicial_pa' => $_ENV['ORGANO_JUDICIAL_PA_URL'] ?? 'https://www.organojudicial.gob.pa/',
            'fiscalia_co' => $_ENV['FISCALIA_CO_URL'] ?? 'https://www.fiscalia.gov.co/',
            'procuraduria_co' => $_ENV['PROCURADURIA_CO_URL'] ?? 'https://www.procuraduria.gov.co/',
            'rama_judicial' => $_ENV['RAMA_JUDICIAL_URL'] ?? 'https://www.ramajudicial.gov.co/',
        ],
        'database' => [
            'icij_offshore' => $_ENV['ICIJ_OFFSHORE_URL'] ?? 'https://offshoreleaks.icij.org/',
            'sic_registro' => $_ENV['SIC_REGISTRO_URL'] ?? 'https://www.sic.gov.co/',
            'sic_consultas' => $_ENV['SIC_CONSULTAS_URL'] ?? 'https://www.sic.gov.co/',
        ],
        'media' => [
            'google_mexico' => $_ENV['GOOGLE_MEXICO_URL'] ?? 'https://www.google.com.mx/',
            'milenio' => $_ENV['MILENIO_URL'] ?? 'https://www.milenio.com/',
            'la_silla_rota' => $_ENV['LA_SILLA_ROTA_URL'] ?? 'https://lasillarota.com/',
            'sol_quintana_roo' => $_ENV['SOL_QUINTANA_ROO_URL'] ?? 'https://www.solquintanaroo.mx/',
            '24_horas' => $_ENV['24_HORAS_URL'] ?? 'https://www.24-horas.mx/',
        ],
    ],

    // Configuración de desarrollo y testing
    'development' => [
        'api_debug' => filter_var($_ENV['API_DEBUG'] ?? false, FILTER_VALIDATE_BOOLEAN),
        'testing_enabled' => filter_var($_ENV['TESTING_ENABLED'] ?? false, FILTER_VALIDATE_BOOLEAN),
        'test_database' => $_ENV['TEST_DATABASE'] ?? 'screening_contratacion_test',
    ],

    // Configuración de producción
    'production' => [
        'opcache_enabled' => filter_var($_ENV['OPCACHE_ENABLED'] ?? false, FILTER_VALIDATE_BOOLEAN),
        'gzip_enabled' => filter_var($_ENV['GZIP_ENABLED'] ?? false, FILTER_VALIDATE_BOOLEAN),
        'minify_css' => filter_var($_ENV['MINIFY_CSS'] ?? false, FILTER_VALIDATE_BOOLEAN),
        'minify_js' => filter_var($_ENV['MINIFY_JS'] ?? false, FILTER_VALIDATE_BOOLEAN),
        'metrics_enabled' => filter_var($_ENV['METRICS_ENABLED'] ?? false, FILTER_VALIDATE_BOOLEAN),
    ],

    // Configuración de health check y monitoreo
    'monitoring' => [
        'health_check_enabled' => filter_var($_ENV['HEALTH_CHECK_ENABLED'] ?? true, FILTER_VALIDATE_BOOLEAN),
        'health_check_interval' => 60, // segundos
        'performance_tracking' => true,
        'error_reporting' => true,
    ],
];
