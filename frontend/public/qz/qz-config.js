/**
 * QZ Tray Configuration — POS System
 * يحدد عنوان السيرفر تلقائياً ويُعِدّ الشهادات الرقمية
 */

function getQZTrayHost() {
    const h = window.location.hostname;
    const isLocal = h === 'localhost' || h === '127.0.0.1' || h === 'pos-app' || h === '';
    return isLocal ? 'localhost' : h;
}

window.QZ_CONFIG = {
    host:      getQZTrayHost(),
    port:      { secure: [8181, 8282, 8383, 8484], insecure: [8182, 8283, 8384, 8485] },
    keepAlive: 60,
    retries:   2,
    delay:     0,
    /** URL of sign-message.php relative to origin — e.g. /pos/backend/sign-message.php */
    signUrl:   '/sign-message.php',
    /** URL of digital-certificate.txt in the public folder */
    certUrl:   '/digital-certificate.txt',
};

window.getQZTrayHost = getQZTrayHost;

console.log('[QZ Config] host =', window.QZ_CONFIG.host);
