# BizCity Tool Image

BizCity Tool Image provides the Image Studio, prompt templates, design editor assets, profile studio, QR studio, and image-generation integrations for WordPress.

## Requirements

- WordPress 6.3 or newer
- PHP 7.4 or newer
- BizCity Twin AI active
- A configured BizCity gateway for provider-backed image generation

## Runtime surfaces

- `/tool-image/` for Image Studio
- `/canva/` for the design editor
- `/profile-studio/` for the profile studio
- `/qr-studio/` for QR workflows
- REST namespace `bztool-image/v1`

Provider calls are routed through the canonical BizCity client/gateway boundary. The plugin does not declare framework SDK capability interfaces in `manifest.json`; its legacy WordPress REST and intent integration remains owned by the plugin runtime.
