/**
 * Runtime Migrator Delegate
 * Delegating all operations to modularized sub-modules under ./migration
 */
const migration = require('./migration');

module.exports = {
  ...migration
};
