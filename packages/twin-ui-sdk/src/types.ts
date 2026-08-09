export type ExtensionZone = 'customer' | 'admin';

export interface CapabilityDescriptor {
  id: string;
  label: string;
  description?: string;
  class?: string;
  schema?: Record<string, unknown>;
  primary?: boolean;
  zone?: ExtensionZone;
  artifact_type?: string;
  side_effects?: string[];
}

export interface ExtensionCapabilities {
  tools?: CapabilityDescriptor[];
  skills?: CapabilityDescriptor[];
  agents?: CapabilityDescriptor[];
  channels?: CapabilityDescriptor[];
  kg_source_adapters?: CapabilityDescriptor[];
  workflow_blocks?: CapabilityDescriptor[];
  personas?: CapabilityDescriptor[];
  output_renderers?: CapabilityDescriptor[];
}

export interface ExtensionRequirements {
  framework?: string;
  php?: string;
  wp?: string;
}

export type ScopeLevel = 'tenant' | 'site' | 'user';

export interface ScopeBinding {
  permission: string;
  scope_level: ScopeLevel;
}

export type ApprovalGate =
  | 'send_message'
  | 'publish_content'
  | 'create_order'
  | 'delete_data'
  | 'execute_payment';

export interface WebhookSignaturePolicy {
  required: boolean;
  algorithm: 'hmac-sha256' | 'hmac-sha512' | 'ed25519';
  header?: string;
}

export interface NetworkPolicy {
  allow_hosts?: string[];
  block_private_ranges?: boolean;
}

export interface UploadPolicy {
  allowed_mime?: string[];
  max_bytes?: number;
  scan_required?: boolean;
}

export interface ExtensionSecurity {
  webhook_signature?: WebhookSignaturePolicy;
  secret_refs?: string[];
  network_policy?: NetworkPolicy;
  upload_policy?: UploadPolicy;
}

export interface ExtensionManifest {
  schema_version: '1.0';
  id: string;
  name: string;
  version: `${number}.${number}.${number}`;
  requires?: ExtensionRequirements;
  permissions?: string[];
  scope_bindings?: ScopeBinding[];
  approval_gates?: ApprovalGate[];
  security?: ExtensionSecurity;
  capabilities: ExtensionCapabilities;
}

export function getPrimaryTools(manifest: ExtensionManifest): CapabilityDescriptor[] {
  return (manifest.capabilities.tools ?? []).filter((tool) => tool.primary === true);
}

export function isManifestShape(value: unknown): value is ExtensionManifest {
  if (!value || typeof value !== 'object') {
    return false;
  }

  const manifest = value as Partial<ExtensionManifest>;
  return manifest.schema_version === '1.0'
    && typeof manifest.id === 'string'
    && typeof manifest.name === 'string'
    && typeof manifest.version === 'string'
    && typeof manifest.capabilities === 'object'
    && manifest.capabilities !== null;
}
