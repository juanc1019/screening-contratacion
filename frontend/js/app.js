/**
 * SISTEMA DE SCREENING DE CONTRATACIÓN
 * JavaScript Principal - Funcionalidades Core
 */

// Configuración global de la aplicación
const CONFIG = {
    API_BASE_URL: '../backend/api',
    UPDATE_INTERVAL: 2000, // 2 segundos
    NOTIFICATION_TIMEOUT: 5000,
    MAX_FILE_SIZE: 50 * 1024 * 1024, // 50MB
    ALLOWED_EXTENSIONS: ['xlsx', 'xls', 'csv'],
    PAGINATION_SIZE: 20,
    TOAST_POSITION: 'bottom-end'
};

// Estado global de la aplicación
const AppState = {
    isLoading: false,
    currentUser: null,
    notifications: [],
    activeBatches: [],
    systemHealth: null,
    lastUpdate: null,
    connectionStatus: 'connected'
};

// Clase principal de la aplicación
class ScreeningApp {
    constructor() {
        this.initialized = false;
        this.eventListeners = new Map();
        this.intervals = new Map();
        this.notificationManager = null;
        this.progressManager = null;
        
        this.init();
    }
    
    /**
     * Inicializa la aplicación
     */
    async init() {
        try {
            console.log('🚀 Inicializando Sistema de Screening...');
            
            // Verificar dependencias
            this.checkDependencies();
            
            // Configurar manejadores globales
            this.setupGlobalHandlers();
            
            // Inicializar componentes
            await this.initializeComponents();
            
            // Configurar auto-actualización
            this.setupAutoUpdate();
            
            // Marcar como inicializado
            this.initialized = true;
            
            console.log('✅ Sistema de Screening inicializado correctamente');
            
            // Mostrar notificación de bienvenida
            this.showToast('success', 'Sistema Iniciado', 'Sistema de Screening cargado correctamente');
            
        } catch (error) {
            console.error('❌ Error inicializando aplicación:', error);
            this.showToast('error', 'Error de Inicialización', 'No se pudo cargar el sistema correctamente');
        }
    }
    
    /**
     * Verifica dependencias necesarias
     */
    checkDependencies() {
        // Verificar Bootstrap
        if (typeof bootstrap === 'undefined') {
            throw new Error('Bootstrap 5 no está cargado');
        }
        
        // Verificar fetch API
        if (typeof fetch === 'undefined') {
            throw new Error('Fetch API no está disponible');
        }
        
        console.log('✅ Dependencias verificadas');
    }
    
    /**
     * Configura manejadores globales de eventos
     */
    setupGlobalHandlers() {
        // Manejador de errores globales
        window.addEventListener('error', (event) => {
            console.error('Error global:', event.error);
            this.handleGlobalError(event.error);
        });
        
        // Manejador de promesas rechazadas
        window.addEventListener('unhandledrejection', (event) => {
            console.error('Promesa rechazada:', event.reason);
            this.handleGlobalError(event.reason);
        });
        
        // Detectar pérdida de conexión
        window.addEventListener('online', () => {
            AppState.connectionStatus = 'connected';
            this.showToast('success', 'Conexión Restaurada', 'La conexión a internet se ha restaurado');
        });
        
        window.addEventListener('offline', () => {
            AppState.connectionStatus = 'disconnected';
            this.showToast('warning', 'Sin Conexión', 'Se ha perdido la conexión a internet');
        });
        
        // Configurar teclas de acceso rápido
        document.addEventListener('keydown', (event) => {
            this.handleKeyboardShortcuts(event);
        });
        
        console.log('✅ Manejadores globales configurados');
    }
    
    /**
     * Inicializa componentes de la aplicación
     */
    async initializeComponents() {
        // Inicializar sistema de notificaciones
        if (typeof NotificationManager !== 'undefined') {
            this.notificationManager = new NotificationManager();
        }
        
        // Inicializar sistema de progreso
        if (typeof ProgressManager !== 'undefined') {
            this.progressManager = new ProgressManager();
        }
        
        // Configurar tooltips de Bootstrap
        this.initializeTooltips();
        
        // Configurar dropdowns
        this.initializeDropdowns();
        
        // Cargar datos iniciales
        await this.loadInitialData();
        
        console.log('✅ Componentes inicializados');
    }
    
    /**
     * Inicializa tooltips de Bootstrap
     */
    initializeTooltips() {
        const tooltipTriggerList = [].slice.call(document.querySelectorAll('[data-bs-toggle="tooltip"]'));
        tooltipTriggerList.map(function (tooltipTriggerEl) {
            return new bootstrap.Tooltip(tooltipTriggerEl);
        });
    }
    
    /**
     * Inicializa dropdowns de Bootstrap
     */
    initializeDropdowns() {
        const dropdownElementList = [].slice.call(document.querySelectorAll('[data-bs-toggle="dropdown"]'));
        dropdownElementList.map(function (dropdownToggleEl) {
            return new bootstrap.Dropdown(dropdownToggleEl);
        });
    }
    
    /**
     * Carga datos iniciales de la aplicación
     */
    async loadInitialData() {
        try {
            this.showLoading(true);
            
            // Cargar en paralelo para mejor rendimiento
            const promises = [
                this.updateSystemStats(),
                this.loadNotifications(),
                this.updateSystemHealth()
            ];
            
            await Promise.allSettled(promises);
            
            AppState.lastUpdate = new Date();
            this.updateLastUpdateDisplay();
            
        } catch (error) {
            console.error('Error cargando datos iniciales:', error);
            this.showToast('error', 'Error de Carga', 'No se pudieron cargar algunos datos del sistema');
        } finally {
            this.showLoading(false);
        }
    }
    
    /**
     * Configura auto-actualización de datos
     */
    setupAutoUpdate() {
        // Actualizar estadísticas cada 30 segundos
        this.intervals.set('stats', setInterval(() => {
            this.updateSystemStats();
        }, 30000));
        
        // Actualizar notificaciones cada 10 segundos
        this.intervals.set('notifications', setInterval(() => {
            this.loadNotifications();
        }, 10000));
        
        // Actualizar salud del sistema cada 60 segundos
        this.intervals.set('health', setInterval(() => {
            this.updateSystemHealth();
        }, 60000));
        
        console.log('✅ Auto-actualización configurada');
    }
    
    /**
     * Realiza petición API con manejo de errores
     */
    async apiRequest(endpoint, options = {}) {
        const url = `${CONFIG.API_BASE_URL}/${endpoint}`;
        
        const defaultOptions = {
            headers: {
                'Content-Type': 'application/json',
                'X-Requested-With': 'XMLHttpRequest'
            },
            credentials: 'same-origin'
        };
        
        const finalOptions = { ...defaultOptions, ...options };
        
        try {
            const response = await fetch(url, finalOptions);
            
            if (!response.ok) {
                throw new Error(`HTTP ${response.status}: ${response.statusText}`);
            }
            
            const data = await response.json();
            
            if (!data.success && data.error) {
                throw new Error(data.error);
            }
            
            return data;
            
        } catch (error) {
            console.error(`Error en API ${endpoint}:`, error);
            
            if (error.name === 'TypeError' && error.message.includes('fetch')) {
                throw new Error('Error de conexión. Verifica tu conexión a internet.');
            }
            
            throw error;
        }
    }
    
    /**
     * Actualiza estadísticas del sistema
     */
    async updateSystemStats() {
        try {
            const response = await this.apiRequest('progress.php?action=system_status');
            
            if (response.success) {
                this.updateStatsDisplay(response);
                AppState.systemHealth = response;
            }
            
        } catch (error) {
            console.error('Error actualizando estadísticas:', error);
        }
    }
    
    /**
     * Actualiza display de estadísticas en el DOM
     */
    updateStatsDisplay(data) {
        // Actualizar contadores principales
        this.updateElement('localRecordsCount', this.formatNumber(data.system_stats?.tables?.local_database_records || 0));
        this.updateElement('searchesToday', this.formatNumber(data.system_stats?.tables?.bulk_searches || 0));
        this.updateElement('activeSites', '22'); // Total de sitios configurados
        this.updateElement('queuedJobs', data.queue?.queued || 0);
        
        // Actualizar indicador de estado del sistema
        this.updateSystemStatusIndicator(data.overall_status);
    }
    
    /**
     * Actualiza indicador de estado del sistema
     */
    updateSystemStatusIndicator(status) {
        const indicator = document.getElementById('systemStatus');
        if (!indicator) return;
        
        // Remover clases anteriores
        indicator.className = 'badge pulse';
        
        switch (status) {
            case 'healthy':
                indicator.classList.add('bg-success');
                indicator.title = 'Sistema funcionando correctamente';
                break;
            case 'warning':
                indicator.classList.add('bg-warning');
                indicator.title = 'Sistema con advertencias menores';
                break;
            case 'critical':
                indicator.classList.add('bg-danger');
                indicator.title = 'Sistema con problemas críticos';
                break;
            default:
                indicator.classList.add('bg-secondary');
                indicator.title = 'Estado del sistema desconocido';
        }
    }
    
    /**
     * Carga notificaciones del sistema
     */
    async loadNotifications() {
        try {
            const response = await this.apiRequest('progress.php?action=notifications&limit=10');
            
            if (response.success) {
                AppState.notifications = response.notifications;
                this.updateNotificationsDisplay();
            }
            
        } catch (error) {
            console.error('Error cargando notificaciones:', error);
        }
    }
    
    /**
     * Actualiza display de notificaciones
     */
    updateNotificationsDisplay() {
        const countElement = document.getElementById('notificationCount');
        const listElement = document.getElementById('notificationsList');
        
        if (!countElement || !listElement) return;
        
        const unreadCount = AppState.notifications.length;
        
        // Actualizar contador
        if (unreadCount > 0) {
            countElement.textContent = unreadCount;
            countElement.style.display = 'block';
        } else {
            countElement.style.display = 'none';
        }
        
        // Actualizar lista
        if (unreadCount === 0) {
            listElement.innerHTML = '<li><a class="dropdown-item text-muted">No hay notificaciones</a></li>';
        } else {
            listElement.innerHTML = AppState.notifications.map(notification => 
                this.createNotificationHTML(notification)
            ).join('');
        }
    }
    
    /**
     * Crea HTML para una notificación
     */
    createNotificationHTML(notification) {
        const icon = this.getNotificationIcon(notification.type);
        const timeAgo = this.getTimeAgo(notification.created_at);
        
        return `
            <li>
                <a class="dropdown-item notification-item ${notification.is_read ? '' : 'unread'}" 
                   href="#" onclick="app.markNotificationAsRead('${notification.id}')">
                    <div class="d-flex align-items-start">
                        <div class="notification-icon ${notification.type}">
                            <i class="bi bi-${icon}"></i>
                        </div>
                        <div class="flex-grow-1">
                            <div class="fw-semibold">${this.escapeHtml(notification.title)}</div>
                            <div class="small text-muted">${this.escapeHtml(notification.message)}</div>
                            <div class="small text-muted">${timeAgo}</div>
                        </div>
                    </div>
                </a>
            </li>
        `;
    }
    
    /**
     * Obtiene icono para tipo de notificación
     */
    getNotificationIcon(type) {
        const icons = {
            'info': 'info-circle',
            'success': 'check-circle',
            'warning': 'exclamation-triangle',
            'error': 'x-circle',
            'progress': 'clock'
        };
        return icons[type] || 'bell';
    }
    
    /**
     * Actualiza salud del sistema
     */
    async updateSystemHealth() {
        try {
            const response = await this.apiRequest('progress.php?action=system_status');
            
            if (response.success) {
                this.updateSystemHealthDisplay(response);
            }
            
        } catch (error) {
            console.error('Error actualizando salud del sistema:', error);
        }
    }
    
    /**
     * Actualiza display de salud del sistema
     */
    updateSystemHealthDisplay(data) {
        const container = document.getElementById('systemHealthContent');
        if (!container) return;
        
        const dbStatus = data.database?.status || 'unknown';
        const queueStatus = data.queue?.status || 'unknown';
        const overallStatus = data.overall_status || 'unknown';
        
        container.innerHTML = `
            <div class="mb-3">
                <div class="d-flex justify-content-between align-items-center mb-2">
                    <span class="fw-semibold">Estado General</span>
                    <span class="badge bg-${this.getStatusColor(overallStatus)}">${this.getStatusText(overallStatus)}</span>
                </div>
                <div class="progress" style="height: 6px;">
                    <div class="progress-bar bg-${this.getStatusColor(overallStatus)}" style="width: ${this.getStatusPercentage(overallStatus)}%"></div>
                </div>
            </div>
            
            <div class="row">
                <div class="col-6 mb-2">
                    <div class="d-flex align-items-center">
                        <span class="status-indicator status-${dbStatus}"></span>
                        <small>Base de Datos</small>
                    </div>
                </div>
                <div class="col-6 mb-2">
                    <div class="d-flex align-items-center">
                        <span class="status-indicator status-${queueStatus}"></span>
                        <small>Cola de Trabajos</small>
                    </div>
                </div>
                <div class="col-6 mb-2">
                    <div class="d-flex align-items-center">
                        <span class="status-indicator status-healthy"></span>
                        <small>Scrapers</small>
                    </div>
                </div>
                <div class="col-6 mb-2">
                    <div class="d-flex align-items-center">
                        <span class="status-indicator status-healthy"></span>
                        <small>APIs</small>
                    </div>
                </div>
            </div>
            
            <div class="mt-3 pt-3 border-top">
                <div class="row text-center">
                    <div class="col-4">
                        <div class="small text-muted">PHP</div>
                        <div class="fw-semibold">${data.performance?.php_version || 'N/A'}</div>
                    </div>
                </div>
            </div>
        `;
    }
    
    /**
     * Obtiene color para estado del sistema
     */
    getStatusColor(status) {
        const colors = {
            'healthy': 'success',
            'warning': 'warning', 
            'critical': 'danger',
            'error': 'danger'
        };
        return colors[status] || 'secondary';
    }
    
    /**
     * Obtiene texto para estado del sistema
     */
    getStatusText(status) {
        const texts = {
            'healthy': 'Saludable',
            'warning': 'Advertencia',
            'critical': 'Crítico',
            'error': 'Error'
        };
        return texts[status] || 'Desconocido';
    }
    
    /**
     * Obtiene porcentaje para barra de estado
     */
    getStatusPercentage(status) {
        const percentages = {
            'healthy': 100,
            'warning': 75,
            'critical': 25,
            'error': 0
        };
        return percentages[status] || 50;
    }
    
    /**
     * Marca notificación como leída
     */
    async markNotificationAsRead(notificationId) {
        try {
            const response = await this.apiRequest('notifications.php', {
                method: 'POST',
                body: JSON.stringify({
                    action: 'mark_read',
                    notification_ids: [notificationId]
                })
            });
            
            if (response.success) {
                // Actualizar estado local
                const notification = AppState.notifications.find(n => n.id === notificationId);
                if (notification) {
                    notification.is_read = true;
                }
                this.updateNotificationsDisplay();
            }
            
        } catch (error) {
            console.error('Error marcando notificación como leída:', error);
        }
    }
    
    /**
     * Marca todas las notificaciones como leídas
     */
    async markAllNotificationsAsRead() {
        try {
            const unreadIds = AppState.notifications
                .filter(n => !n.is_read)
                .map(n => n.id);
            
            if (unreadIds.length === 0) return;
            
            const response = await this.apiRequest('notifications.php', {
                method: 'POST',
                body: JSON.stringify({
                    action: 'mark_read',
                    notification_ids: unreadIds
                })
            });
            
            if (response.success) {
                AppState.notifications.forEach(n => n.is_read = true);
                this.updateNotificationsDisplay();
                this.showToast('success', 'Notificaciones', 'Todas las notificaciones marcadas como leídas');
            }
            
        } catch (error) {
            console.error('Error marcando todas las notificaciones:', error);
            this.showToast('error', 'Error', 'No se pudieron marcar las notificaciones');
        }
    }
    
    /**
     * Maneja atajos de teclado
     */
    handleKeyboardShortcuts(event) {
        // Solo procesar si no estamos en un input
        if (event.target.tagName === 'INPUT' || event.target.tagName === 'TEXTAREA') {
            return;
        }
        
        // Ctrl/Cmd + combinaciones
        if (event.ctrlKey || event.metaKey) {
            switch (event.key) {
                case 'r':
                    event.preventDefault();
                    this.refreshData();
                    break;
                case 'n':
                    event.preventDefault();
                    window.location.href = 'search.html';
                    break;
                case 'u':
                    event.preventDefault();
                    window.location.href = 'upload.html';
                    break;
            }
        }
        
        // Teclas simples
        switch (event.key) {
            case 'Escape':
                this.closeAllModals();
                break;
            case 'F5':
                event.preventDefault();
                this.refreshData();
                break;
        }
    }
    
    /**
     * Refresca todos los datos
     */
    async refreshData() {
        this.showToast('info', 'Actualizando', 'Refrescando datos del sistema...');
        await this.loadInitialData();
        this.showToast('success', 'Actualizado', 'Datos del sistema actualizados');
    }
    
    /**
     * Cierra todos los modales abiertos
     */
    closeAllModals() {
        const modals = document.querySelectorAll('.modal.show');
        modals.forEach(modal => {
            const modalInstance = bootstrap.Modal.getInstance(modal);
            if (modalInstance) {
                modalInstance.hide();
            }
        });
    }
    
    /**
     * Maneja errores globales
     */
    handleGlobalError(error) {
        console.error('Error global capturado:', error);
        
        // No mostrar errores muy frecuentes
        const errorMessage = error.message || error.toString();
        if (errorMessage.includes('Script error') || errorMessage.includes('Non-Error promise rejection')) {
            return;
        }
        
        // Mostrar toast de error
        this.showToast('error', 'Error del Sistema', 'Se ha producido un error inesperado');
    }
    
    /**
     * Muestra/oculta overlay de carga
     */
    showLoading(show = true) {
        const overlay = document.getElementById('loadingOverlay');
        if (overlay) {
            overlay.style.display = show ? 'flex' : 'none';
        }
        AppState.isLoading = show;
    }
    
    /**
     * Muestra toast de notificación
     */
    showToast(type, title, message, options = {}) {
        const toastContainer = document.getElementById('toastContainer');
        if (!toastContainer) return;
        
        const toastId = 'toast_' + Date.now();
        const bgClass = this.getToastBgClass(type);
        const icon = this.getNotificationIcon(type);
        
        const toastHTML = `
            <div id="${toastId}" class="toast" role="alert" aria-live="assertive" aria-atomic="true">
                <div class="toast-header ${bgClass} text-white">
                    <i class="bi bi-${icon} me-2"></i>
                    <strong class="me-auto">${this.escapeHtml(title)}</strong>
                    <small>Ahora</small>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="toast"></button>
                </div>
                <div class="toast-body">
                    ${this.escapeHtml(message)}
                </div>
            </div>
        `;
        
        toastContainer.insertAdjacentHTML('beforeend', toastHTML);
        
        const toastElement = document.getElementById(toastId);
        const toast = new bootstrap.Toast(toastElement, {
            autohide: options.autohide !== false,
            delay: options.delay || CONFIG.NOTIFICATION_TIMEOUT
        });
        
        toast.show();
        
        // Limpiar después de que se oculte
        toastElement.addEventListener('hidden.bs.toast', () => {
            toastElement.remove();
        });
    }
    
    /**
     * Obtiene clase de fondo para toast
     */
    getToastBgClass(type) {
        const classes = {
            'success': 'bg-success',
            'error': 'bg-danger',
            'warning': 'bg-warning',
            'info': 'bg-info'
        };
        return classes[type] || 'bg-secondary';
    }
    
    /**
     * Actualiza elemento del DOM si existe
     */
    updateElement(id, content) {
        const element = document.getElementById(id);
        if (element) {
            element.textContent = content;
        }
    }
    
    /**
     * Actualiza display de última actualización
     */
    updateLastUpdateDisplay() {
        if (AppState.lastUpdate) {
            this.updateElement('lastUpdate', this.formatDateTime(AppState.lastUpdate));
        }
    }
    
    /**
     * Formatea números con separadores de miles
     */
    formatNumber(num) {
        return new Intl.NumberFormat('es-ES').format(num);
    }
    
    /**
     * Formatea fecha y hora
     */
    formatDateTime(date) {
        return new Intl.DateTimeFormat('es-ES', {
            day: '2-digit',
            month: '2-digit',
            year: 'numeric',
            hour: '2-digit',
            minute: '2-digit'
        }).format(new Date(date));
    }
    
    /**
     * Calcula tiempo transcurrido desde una fecha
     */
    getTimeAgo(dateString) {
        const date = new Date(dateString);
        const now = new Date();
        const diff = now - date;
        
        const minutes = Math.floor(diff / 60000);
        const hours = Math.floor(minutes / 60);
        const days = Math.floor(hours / 24);
        
        if (days > 0) return `hace ${days} día${days > 1 ? 's' : ''}`;
        if (hours > 0) return `hace ${hours} hora${hours > 1 ? 's' : ''}`;
        if (minutes > 0) return `hace ${minutes} minuto${minutes > 1 ? 's' : ''}`;
        return 'hace un momento';
    }
    
    /**
     * Formatea tiempo de uptime
     */
    formatUptime(seconds) {
        const days = Math.floor(seconds / 86400);
        const hours = Math.floor((seconds % 86400) / 3600);
        const minutes = Math.floor((seconds % 3600) / 60);
        
        if (days > 0) return `${days}d ${hours}h`;
        if (hours > 0) return `${hours}h ${minutes}m`;
        return `${minutes}m`;
    }
    
    /**
     * Escapa HTML para prevenir XSS
     */
    escapeHtml(text) {
        const div = document.createElement('div');
        div.textContent = text;
        return div.innerHTML;
    }
    
    /**
     * Valida archivo antes de subir
     */
    validateFile(file) {
        const errors = [];
        
        // Validar tamaño
        if (file.size > CONFIG.MAX_FILE_SIZE) {
            errors.push(`El archivo excede el tamaño máximo de ${CONFIG.MAX_FILE_SIZE / (1024 * 1024)}MB`);
        }
        
        // Validar extensión
        const extension = file.name.split('.').pop().toLowerCase();
        if (!CONFIG.ALLOWED_EXTENSIONS.includes(extension)) {
            errors.push(`Tipo de archivo no permitido. Use: ${CONFIG.ALLOWED_EXTENSIONS.join(', ')}`);
        }
        
        return {
            valid: errors.length === 0,
            errors: errors
        };
    }
    
    /**
     * Formatea tamaño de archivo
     */
    formatFileSize(bytes) {
        if (bytes === 0) return '0 Bytes';
        
        const k = 1024;
        const sizes = ['Bytes', 'KB', 'MB', 'GB'];
        const i = Math.floor(Math.log(bytes) / Math.log(k));
        
        return parseFloat((bytes / Math.pow(k, i)).toFixed(2)) + ' ' + sizes[i];
    }
    
    /**
     * Destructor de la aplicación
     */
    destroy() {
        // Limpiar intervalos
        this.intervals.forEach(interval => clearInterval(interval));
        this.intervals.clear();
        
        // Limpiar event listeners
        this.eventListeners.clear();
        
        // Limpiar componentes
        if (this.notificationManager) {
            this.notificationManager.destroy();
        }
        
        if (this.progressManager) {
            this.progressManager.destroy();
        }
        
        console.log('🧹 Aplicación destruida correctamente');
    }
}

// Utilidades globales
const Utils = {
    /**
     * Debounce para funciones
     */
    debounce(func, wait) {
        let timeout;
        return function executedFunction(...args) {
            const later = () => {
                clearTimeout(timeout);
                func(...args);
            };
            clearTimeout(timeout);
            timeout = setTimeout(later, wait);
        };
    },
    
    /**
     * Throttle para funciones
     */
    throttle(func, limit) {
        let inThrottle;
        return function() {
            const args = arguments;
            const context = this;
            if (!inThrottle) {
                func.apply(context, args);
                inThrottle = true;
                setTimeout(() => inThrottle = false, limit);
            }
        };
    },
    
    /**
     * Genera ID único
     */
    generateId() {
        return Date.now().toString(36) + Math.random().toString(36).substr(2);
    },
    
    /**
     * Copia texto al portapapeles
     */
    async copyToClipboard(text) {
        try {
            await navigator.clipboard.writeText(text);
            return true;
        } catch (err) {
            console.error('Error copiando al portapapeles:', err);
            return false;
        }
    },
    
    /**
     * Descarga contenido como archivo
     */
    downloadFile(content, filename, type = 'text/plain') {
        const blob = new Blob([content], { type });
        const url = window.URL.createObjectURL(blob);
        
        const a = document.createElement('a');
        a.href = url;
        a.download = filename;
        document.body.appendChild(a);
        a.click();
        
        window.URL.revokeObjectURL(url);
        document.body.removeChild(a);
    }
};

// Funciones globales de conveniencia
function refreshActiveBatches() {
    if (window.app && typeof window.app.refreshActiveBatches === 'function') {
        window.app.refreshActiveBatches();
    }
}

function markAllNotificationsRead() {
    if (window.app) {
        window.app.markAllNotificationsAsRead();
    }
}

// Inicializar aplicación cuando el DOM esté listo
document.addEventListener('DOMContentLoaded', () => {
    // Inicializar aplicación global
    window.app = new ScreeningApp();
    
    // Configurar manejador para el botón de marcar notificaciones como leídas
    const markAllReadBtn = document.getElementById('markAllRead');
    if (markAllReadBtn) {
        markAllReadBtn.addEventListener('click', (e) => {
            e.preventDefault();
            markAllNotificationsRead();
        });
    }
});

// Limpiar al salir de la página
window.addEventListener('beforeunload', () => {
    if (window.app) {
        window.app.destroy();
    }
});

// Exportar para uso en otros módulos
if (typeof module !== 'undefined' && module.exports) {
    module.exports = { ScreeningApp, Utils, CONFIG, AppState };
}-muted">Memoria</div>
                        <div class="fw-semibold">${data.performance?.memory_usage_mb || 0}MB</div>
                    </div>
                    <div class="col-4">
                        <div class="small text-muted">Uptime</div>
                        <div class="fw-semibold">${this.formatUptime(data.performance?.uptime_seconds || 0)}</div>
                    </div>
                    <div class="col-4">
                        <div class="small text