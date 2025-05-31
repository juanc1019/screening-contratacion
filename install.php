<?php
/**
 * Script de instalación automatizada
 */

echo "🚀 Iniciando instalación del Sistema de Screening\n\n";

// Verificar PHP
if (version_compare(PHP_VERSION, '8.0.0') < 0) {
    die("❌ Se requiere PHP 8.0 o superior. Versión actual: " . PHP_VERSION . "\n");
}
echo "✅ PHP " . PHP_VERSION . " detectado\n";

// Verificar extensiones requeridas
$requiredExtensions = ['pdo', 'pgsql', 'mbstring', 'curl', 'zip', 'json'];
foreach ($requiredExtensions as $ext) {
    if (!extension_loaded($ext)) {
        die("❌ Extensión PHP requerida no encontrada: {$ext}\n");
    }
}
echo "✅ Todas las extensiones PHP requeridas están disponibles\n";

// Verificar Composer
if (!file_exists('composer.json')) {
    die("❌ composer.json no encontrado\n");
}

echo "📦 Instalando dependencias PHP...\n";
system('composer install --no-dev --optimize-autoloader');

// Crear .env si no existe
if (!file_exists('.env') && file_exists('.env.example')) {
    copy('.env.example', '.env');
    echo "✅ Archivo .env creado desde .env.example\n";
    echo "⚠️  IMPORTANTE: Edita .env con tus credenciales de PostgreSQL\n";
}

// Verificar directorios con permisos
$directories = ['uploads', 'logs', 'exports'];
foreach ($directories as $dir) {
    if (!is_dir($dir)) {
        mkdir($dir, 0755, true);
    }
    if (!is_writable($dir)) {
        echo "⚠️  Directorio {$dir} no tiene permisos de escritura\n";
    }
}

echo "\n🎉 Instalación completada!\n";
echo "📋 Próximos pasos:\n";
echo "   1. Editar .env con credenciales de PostgreSQL\n";
echo "   2. Ejecutar: psql -d screening_contratacion -f database/schema.sql\n";
echo "   3. Probar: php backend/workers/db_setup.php\n";
echo "   4. Instalar scrapers: cd scrapers && npm install\n";