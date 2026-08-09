# BizCity Reference Extension

This fixture demonstrates the public SDK contracts and runtime discovery path.

## Static checks

From the `bizcity-twin-ai` root:

```text
php bin/bizcity-manifest-validate.php --plugin=examples/bizcity-reference-plugin
node core/twin-core/contracts/tests/run-contract-tests.mjs
```

## WordPress runtime smoke

Load the plugin after `bizcity-twin-ai`, then run:

```php
$groups = apply_filters( 'bizcity_twin_register_extension_capabilities', array() );
BizCity_Twin_Content_Registry::boot();

foreach ( array( 'tools', 'skills', 'agents', 'channels', 'kg_source_adapters', 'workflow_blocks', 'personas', 'output_renderers' ) as $kind ) {
    if ( count( BizCity_Twin_Content_Registry::all( $kind ) ) !== 1 ) {
        throw new RuntimeException( 'Reference registry failed for ' . $kind );
    }
}
```

The smoke confirms that each interface implementation is discoverable through
one framework registry, rather than only being present as an unreferenced PHP
class.
