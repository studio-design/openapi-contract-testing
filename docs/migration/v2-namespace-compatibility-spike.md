# v2 namespace compatibility spike

This spike verifies the behavior and limits of a temporary namespace alias
mechanism before the v2 source rename. It does not add aliases to the released
package.

## Candidate mechanism

The candidate is a small autoloader registered from Composer's `autoload.files`:

1. Ignore symbols outside `Studio\OpenApiContractTesting\`.
2. Replace that prefix with `Studio\Gesso\`.
3. Ask Composer to autoload the canonical symbol.
4. Create the requested legacy alias with `class_alias()`.

Registration is lazy. In particular, it does not load Laravel, Symfony, Pest,
or other optional adapters merely because `vendor/autoload.php` was included.
Composer loads `autoload.files` after registering its own autoloader, so the
alias loader can delegate the canonical lookup back to Composer.

The executable fixture in
`tests/fixtures/namespace-compatibility` rebuilds an isolated package with
`composer dump-autoload --classmap-authoritative`. It verifies classes,
interfaces, traits, enums, attributes, shared static state, reflection,
serialization, legacy serialized input, and an unloaded optional adapter.

## Proven behavior

- Classes, interfaces, traits, enums, and attributes can be resolved through
  the legacy name on the PHP 8.2+ support line.
- Both names refer to the same runtime type and share static state.
- A legacy class name embedded in a serialized object can be resolved during
  `unserialize()` when the alias loader is active.
- The mechanism works with Composer's optimized authoritative classmap because
  only the canonical Gesso symbols need classmap entries.
- Unknown legacy names remain unknown, and optional integrations are not loaded
  eagerly.

## Compatibility limits

An alias provides source compatibility, not two independent class identities:

- `get_class()` and reflection return the canonical declared FQCN.
- `serialize()` writes the canonical declared FQCN.
- Code or snapshots comparing literal FQCN strings can therefore still change
  across the canonical rename.
- PHP functions, namespace constants, Composer package requirements, PHPUnit
  XML, Laravel configuration, commands, and machine-readable branding are not
  covered by `class_alias()`.

The v1 public inventory contains types rather than public namespaced functions,
so the PHP-symbol portion is technically aliasable. The other surfaces still
need their dedicated migration steps.

## Recommendation

Use this mechanism only as a time-bounded migration aid, not as a permanent
second public identity. The lowest-risk sequence is:

1. In the final v1 minor, optionally expose lazy `Studio\Gesso\` aliases whose
   canonical declarations remain under `Studio\OpenApiContractTesting\`.
2. Tell consumers that reflection and serialized output keep the v1 canonical
   name until v2; do not promise literal-FQCN identity stability through aliases.
3. In v2, declare only `Studio\Gesso\` as canonical. If a reverse legacy shim is
   approved, give it an explicit removal release and test it with this fixture.
4. Do not keep reverse aliases by default when the goal is a complete identity
   migration; the major-version boundary already provides the source-breaking
   migration point.

This leaves the exact decision to ship aliases in v1 or v2 as a release-policy
choice, while removing uncertainty about the mechanism itself.

## Sources

- [PHP `class_alias()`](https://www.php.net/class-alias)
- [Composer autoload `files`](https://getcomposer.org/doc/04-schema.md#files)
- [Composer autoloader optimization](https://getcomposer.org/doc/articles/autoloader-optimization.md)
