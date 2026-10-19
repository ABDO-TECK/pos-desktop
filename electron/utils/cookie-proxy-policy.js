const TRUSTED_APP_ORIGIN = 'app://pos-app';
const LOCAL_HOSTNAMES = new Set(['127.0.0.1', 'localhost', '::1', '[::1]']);
const COOKIE_PATHS = Object.freeze({
  pos_token: '/',
  pos_refresh_token: '/api/v1/refresh',
  'XSRF-TOKEN': '/',
});

function isTrustedInitiator(initiator) {
  if (initiator === TRUSTED_APP_ORIGIN) return true;
  if (typeof initiator !== 'string' || initiator === '') return false;

  try {
    const parsed = new URL(initiator);
    return parsed.protocol === 'app:'
      && parsed.hostname === 'pos-app'
      && parsed.port === ''
      && parsed.username === ''
      && parsed.password === '';
  } catch {
    return false;
  }
}

function isLocalPhpBackendUrl(url, phpPort) {
  if (!Number.isInteger(phpPort) || phpPort < 1 || phpPort > 65535) {
    return false;
  }

  let parsedUrl;
  try {
    parsedUrl = new URL(url);
  } catch {
    return false;
  }

  const port = parsedUrl.port ? Number(parsedUrl.port) : 80;
  return parsedUrl.protocol === 'http:'
    && LOCAL_HOSTNAMES.has(parsedUrl.hostname)
    && port === phpPort;
}

function isTrustedBackendRequest({
  url,
  phpPort,
  initiator,
  webContentsId,
  trustedWebContentsId,
  frameUrl,
}) {
  if (!isLocalPhpBackendUrl(url, phpPort)) return false;

  // webContents identifies the window, not the requesting frame. Require the
  // current frame URL as a second identity signal so an opaque data: frame or
  // foreign subframe cannot inherit the window's session credentials.
  if (!isTrustedInitiator(frameUrl)) return false;
  if (!Number.isInteger(webContentsId)
    || !Number.isInteger(trustedWebContentsId)
    || webContentsId !== trustedWebContentsId) {
    return false;
  }

  if (isTrustedInitiator(initiator)) return true;

  // Chromium may omit the initiator for requests originating from the
  // privileged app:// renderer. Bind that fallback to the current app frame
  // and main window so a foreign renderer cannot receive or send credentials.
  return initiator === '' || initiator === undefined;
}

function isCookiePathAllowed(requestPath, cookiePath) {
  if (typeof requestPath !== 'string' || typeof cookiePath !== 'string') {
    return false;
  }

  if (requestPath === cookiePath) return true;
  if (!requestPath.startsWith(cookiePath)) return false;
  return cookiePath.endsWith('/') || requestPath[cookiePath.length] === '/';
}

function getCookieHeader(cookies, requestPath) {
  return Object.entries(cookies || {})
    .filter(([name, value]) => (
      Object.prototype.hasOwnProperty.call(COOKIE_PATHS, name)
      && typeof value === 'string'
      && value !== ''
      && isCookiePathAllowed(requestPath, COOKIE_PATHS[name])
    ))
    .map(([name, value]) => `${name}=${value}`)
    .join('; ');
}

module.exports = {
  COOKIE_PATHS,
  TRUSTED_APP_ORIGIN,
  getCookieHeader,
  isCookiePathAllowed,
  isLocalPhpBackendUrl,
  isTrustedBackendRequest,
  isTrustedInitiator,
};
