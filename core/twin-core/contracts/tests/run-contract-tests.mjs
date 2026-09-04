import fs from 'node:fs';
import path from 'node:path';
import assert from 'node:assert/strict';
import { fileURLToPath } from 'node:url';

const __filename = fileURLToPath(import.meta.url);
const __dirname = path.dirname(__filename);
const schemaRoot = path.resolve(__dirname, '../schema/public/v1');
const catalogPath = path.join(schemaRoot, 'contract-catalog.json');

function readJson(filePath) {
  return JSON.parse(fs.readFileSync(filePath, 'utf8'));
}

function isDateTime(value) {
  if (typeof value !== 'string') {
    return false;
  }
  const timestamp = Date.parse(value);
  return Number.isFinite(timestamp) && value.includes('T');
}

function resolveRef(schema, ref) {
  if (!ref.startsWith('#/')) {
    throw new Error(`Only local refs are supported in contract tests: ${ref}`);
  }
  const parts = ref.slice(2).split('/');
  let current = schema;
  for (const part of parts) {
    if (!Object.prototype.hasOwnProperty.call(current, part)) {
      throw new Error(`Broken schema ref: ${ref}`);
    }
    current = current[part];
  }
  return current;
}

function validate(schema, value, pathLabel = '$', rootSchema = schema) {
  const errors = [];

  if (schema.$ref) {
    const resolved = resolveRef(rootSchema, schema.$ref);
    return validate(resolved, value, pathLabel, rootSchema);
  }

  if (schema.const !== undefined && value !== schema.const) {
    errors.push(`${pathLabel}: expected const ${JSON.stringify(schema.const)}`);
  }

  if (schema.enum && !schema.enum.includes(value)) {
    errors.push(`${pathLabel}: expected one of ${schema.enum.join(', ')}`);
  }

  if (schema.type) {
    if (schema.type === 'object') {
      if (typeof value !== 'object' || value === null || Array.isArray(value)) {
        errors.push(`${pathLabel}: expected object`);
        return errors;
      }

      if (Array.isArray(schema.required)) {
        for (const key of schema.required) {
          if (!Object.prototype.hasOwnProperty.call(value, key)) {
            errors.push(`${pathLabel}: missing required property '${key}'`);
          }
        }
      }

      if (schema.additionalProperties === false && schema.properties) {
        for (const key of Object.keys(value)) {
          if (!Object.prototype.hasOwnProperty.call(schema.properties, key)) {
            errors.push(`${pathLabel}: unexpected property '${key}'`);
          }
        }
      }

      if (schema.properties) {
        for (const [key, propertySchema] of Object.entries(schema.properties)) {
          if (Object.prototype.hasOwnProperty.call(value, key)) {
            errors.push(...validate(propertySchema, value[key], `${pathLabel}.${key}`, rootSchema));
          }
        }
      }

      return errors;
    }

    if (schema.type === 'array') {
      if (!Array.isArray(value)) {
        errors.push(`${pathLabel}: expected array`);
        return errors;
      }

      if (schema.minItems !== undefined && value.length < schema.minItems) {
        errors.push(`${pathLabel}: expected at least ${schema.minItems} items`);
      }

      if (schema.maxItems !== undefined && value.length > schema.maxItems) {
        errors.push(`${pathLabel}: expected at most ${schema.maxItems} items`);
      }

      if (schema.uniqueItems) {
        const seen = new Set(value.map((entry) => JSON.stringify(entry)));
        if (seen.size !== value.length) {
          errors.push(`${pathLabel}: expected unique items`);
        }
      }

      if (schema.items) {
        value.forEach((entry, index) => {
          errors.push(...validate(schema.items, entry, `${pathLabel}[${index}]`, rootSchema));
        });
      }

      return errors;
    }

    if (schema.type === 'string') {
      if (typeof value !== 'string') {
        errors.push(`${pathLabel}: expected string`);
        return errors;
      }

      if (schema.minLength !== undefined && value.length < schema.minLength) {
        errors.push(`${pathLabel}: minLength ${schema.minLength}`);
      }

      if (schema.maxLength !== undefined && value.length > schema.maxLength) {
        errors.push(`${pathLabel}: maxLength ${schema.maxLength}`);
      }

      if (schema.pattern) {
        const regex = new RegExp(schema.pattern);
        if (!regex.test(value)) {
          errors.push(`${pathLabel}: does not match pattern ${schema.pattern}`);
        }
      }

      if (schema.format === 'date-time' && !isDateTime(value)) {
        errors.push(`${pathLabel}: expected ISO date-time`);
      }

      return errors;
    }

    if (schema.type === 'integer') {
      if (!Number.isInteger(value)) {
        errors.push(`${pathLabel}: expected integer`);
        return errors;
      }
      if (schema.minimum !== undefined && value < schema.minimum) {
        errors.push(`${pathLabel}: minimum ${schema.minimum}`);
      }
      if (schema.maximum !== undefined && value > schema.maximum) {
        errors.push(`${pathLabel}: maximum ${schema.maximum}`);
      }
      return errors;
    }

    if (schema.type === 'number') {
      if (typeof value !== 'number' || Number.isNaN(value)) {
        errors.push(`${pathLabel}: expected number`);
        return errors;
      }
      if (schema.minimum !== undefined && value < schema.minimum) {
        errors.push(`${pathLabel}: minimum ${schema.minimum}`);
      }
      if (schema.maximum !== undefined && value > schema.maximum) {
        errors.push(`${pathLabel}: maximum ${schema.maximum}`);
      }
      return errors;
    }

    if (schema.type === 'boolean') {
      if (typeof value !== 'boolean') {
        errors.push(`${pathLabel}: expected boolean`);
      }
      return errors;
    }
  }

  return errors;
}

function isSemver(version) {
  return /^[0-9]+\.[0-9]+\.[0-9]+(?:-[0-9A-Za-z.-]+)?(?:\+[0-9A-Za-z.-]+)?$/.test(version);
}

function isFrameworkRange(range) {
  return /^(?:(?:\^|~|>=|>|<=|<|=)?[0-9]+\.[0-9]+(?:\.[0-9]+|\.x)?)(?:\s+(?:(?:\^|~|>=|>|<=|<|=)?[0-9]+\.[0-9]+(?:\.[0-9]+|\.x)?))*$/.test(range.trim());
}

const supportedManifestCapabilities = new Set([
  'channel.inbound',
  'channel.outbound',
  'action.notify',
  'context.admit',
  'commerce.order.read',
  'fulfillment.read',
]);

function validateExtensionManifestSemantics(manifest, label) {
  assert.equal(manifest.contract, 'extension-manifest', `${label}: contract id must be extension-manifest`);
  assert.ok(isSemver(manifest.version), `${label}: contract version must be semver`);
  assert.ok(/^[a-z][a-z0-9._-]{2,63}$/.test(manifest.extension_id), `${label}: extension_id must be a stable slug`);
  assert.ok(isSemver(manifest.extension_version), `${label}: extension_version must be semver`);
  assert.ok(isFrameworkRange(manifest.requires_framework), `${label}: requires_framework must be a supported semver range`);
  assert.ok(manifest.capabilities.length > 0, `${label}: capabilities must not be empty`);
  for (const capability of manifest.capabilities) {
    assert.ok(supportedManifestCapabilities.has(capability),
      `${label}: unsupported required capability: ${capability}`);
  }

  const channelSlugs = new Set();
  for (const channel of manifest.channels) {
    assert.ok(!channelSlugs.has(channel.slug), `${label}: duplicate channel slug: ${channel.slug}`);
    channelSlugs.add(channel.slug);
    assert.ok(channel.surface_policy.length > 0, `${label}: channel surface_policy must not be empty`);
    if (channel.zone === 'customer') {
      assert.ok(channel.surface_policy.includes('gpt_member') || channel.surface_policy.includes('gpt_guest'),
        `${label}: customer channel must declare a GPT surface`);
    }
    if (channel.crm_policy === 'enabled') {
      assert.notEqual(channel.context_policy, 'none',
        `${label}: CRM-enabled channel must declare a context policy`);
    }
  }

  assert.ok(manifest.diagnostics.requires.includes('disk'), `${label}: diagnostics must require disk evidence`);
  assert.ok(manifest.diagnostics.requires.includes('loader'), `${label}: diagnostics must require loader evidence`);
  assert.ok(manifest.diagnostics.requires.includes('runtime'), `${label}: diagnostics must require runtime evidence`);
}

function run() {
  const catalog = readJson(catalogPath);

  assert.ok(isSemver(catalog.catalog_version), 'catalog_version must be semver');
  assert.equal(catalog.semver_policy, 'semver', 'catalog semver policy must be semver');
  assert.ok(catalog.default_deprecation_policy.grace_versions_min >= 2, 'minimum deprecation grace must be at least 2 versions');

  const ids = new Set();
  for (const contract of catalog.contracts) {
    assert.ok(!ids.has(contract.id), `duplicate contract id: ${contract.id}`);
    ids.add(contract.id);

    assert.ok(isSemver(contract.version), `${contract.id}: version must be semver`);
    assert.ok(contract.deprecation.grace_versions >= 2, `${contract.id}: grace_versions must be >= 2`);
    assert.ok(contract.deprecation.grace_versions <= 3, `${contract.id}: grace_versions should be <= 3 for current policy`);

    const schemaPath = path.join(schemaRoot, contract.schema);
    const validPath = path.join(schemaRoot, contract.fixtures.valid);
    const invalidPath = path.join(schemaRoot, contract.fixtures.invalid);

    const schema = readJson(schemaPath);
    const validFixture = readJson(validPath);
    const invalidFixture = readJson(invalidPath);

    const validErrors = validate(schema, validFixture, '$', schema);
    if (validErrors.length > 0) {
      throw new Error(`${contract.id}: valid fixture failed\n${validErrors.join('\n')}`);
    }

    const invalidErrors = validate(schema, invalidFixture, '$', schema);
    if (invalidErrors.length === 0) {
      throw new Error(`${contract.id}: invalid fixture unexpectedly passed`);
    }

    if (contract.id === 'extension-manifest') {
      validateExtensionManifestSemantics(validFixture, `${contract.id} valid fixture`);
    }

    const additionalValidFixtures = contract.fixtures.additional_valid ?? [];
    for (const fixtureRef of additionalValidFixtures) {
      const fixturePath = path.join(schemaRoot, fixtureRef);
      const fixture = readJson(fixturePath);
      const fixtureErrors = validate(schema, fixture, '$', schema);
      if (fixtureErrors.length > 0) {
        throw new Error(`${contract.id}: additional fixture ${fixtureRef} failed\n${fixtureErrors.join('\n')}`);
      }
      if (contract.id === 'extension-manifest') {
        validateExtensionManifestSemantics(fixture, `${contract.id} ${fixtureRef}`);
      }
    }

    const additionalInvalidFixtures = contract.fixtures.additional_invalid ?? [];
    for (const fixtureRef of additionalInvalidFixtures) {
      const fixturePath = path.join(schemaRoot, fixtureRef);
      const fixture = readJson(fixturePath);
      const fixtureErrors = validate(schema, fixture, '$', schema);
      if (fixtureErrors.length === 0) {
        assert.throws(
          () => validateExtensionManifestSemantics(fixture, `${contract.id} ${fixtureRef}`),
          `${contract.id}: additional fixture ${fixtureRef} should fail semantic validation`
        );
      }
    }

    if (contract.id === 'admin-navigation') {
      assert.equal(validFixture.top_level_groups.length, 3,
        'admin-navigation must define exactly three top-level groups');
      assert.deepEqual(
        validFixture.top_level_groups.map((group) => group.id).sort(),
        ['diagnostics', 'settings', 'workspace'],
        'admin-navigation top-level groups must be settings, workspace and diagnostics'
      );
      const pairKeys = new Set();
      for (const item of validFixture.items) {
        const pairKey = `${item.parent}:${item.slug}`;
        assert.ok(!pairKeys.has(pairKey), `admin-navigation duplicate parent/slug: ${pairKey}`);
        pairKeys.add(pairKey);
        assert.ok(['settings', 'workspace', 'diagnostics'].includes(item.group),
          `admin-navigation item has invalid group: ${item.id}`);
        assert.ok(item.slot.startsWith(`${item.group}.`),
          `admin-navigation item slot must belong to group: ${item.id}`);
        assert.ok(['core', 'bundle', 'extension'].includes(item.origin),
          `admin-navigation item has invalid origin: ${item.id}`);
      }
    }
  }

  const frameworkContractsPath = path.join(__dirname, '..', 'framework-contracts.php');
  const phoneNormalizerPath = path.resolve(__dirname, '../../../helper/includes/class-bizcity-phone-normalizer.php');
  const frameworkSource = fs.readFileSync(frameworkContractsPath, 'utf8');
  const phoneNormalizerSource = fs.readFileSync(phoneNormalizerPath, 'utf8');
  assert.match(frameworkSource, /interface\s+BizCity_Phone_Normalizer_Interface/,
    'phone normalizer public framework contract is missing');
  assert.match(phoneNormalizerSource, /implements\s+BizCity_Phone_Normalizer_Interface/,
    'canonical phone normalizer does not implement its public contract');
  assert.match(phoneNormalizerSource, /function\s+normalize_vn\s*\(/,
    'canonical phone normalizer method is missing');
  assert.match(frameworkSource, /interface\s+BizCity_Admin_Navigation_Provider_Interface/,
    'admin navigation provider contract is missing');
  assert.match(frameworkSource, /class\s+BizCity_Admin_Navigation_Item/,
    'admin navigation DTO is missing');
  const navigationRegistryPath = path.join(__dirname, '..', 'class-admin-navigation-registry.php');
  const navigationRegistrySource = fs.readFileSync(navigationRegistryPath, 'utf8');
  assert.match(navigationRegistrySource, /class\s+BizCity_Admin_Navigation_Registry/,
    'admin navigation registry is missing');
  assert.match(navigationRegistrySource, /register_provider\s*\(/,
    'admin navigation registry provider entry point is missing');

  console.log(`CONTRACT TESTS PASS (${catalog.contracts.length} contracts)`);
}

run();
