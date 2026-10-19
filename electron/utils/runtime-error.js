function createRuntimeError(code, message, details = {}) {
  const error = new Error(message);
  error.code = code;
  error.details = details;
  return error;
}

function serializeRuntimeError(error) {
  if (!error) return null;

  return {
    code: error.code || null,
    message: error.message || String(error),
    details: error.details || null,
    cause: error.cause && error.cause.message ? error.cause.message : null,
  };
}

module.exports = { createRuntimeError, serializeRuntimeError };
