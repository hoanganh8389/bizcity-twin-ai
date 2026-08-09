export function getPrimaryTools(manifest) {
    return (manifest.capabilities.tools ?? []).filter((tool) => tool.primary === true);
}
export function isManifestShape(value) {
    if (!value || typeof value !== 'object') {
        return false;
    }
    const manifest = value;
    return manifest.schema_version === '1.0'
        && typeof manifest.id === 'string'
        && typeof manifest.name === 'string'
        && typeof manifest.version === 'string'
        && typeof manifest.capabilities === 'object'
        && manifest.capabilities !== null;
}
