# Composer related build rules.

def _composer_repository_impl(repository_ctx):
  result = repository_ctx.execute(
      [repository_ctx.attr.composer_bin,
       'require',
       '--prefer-dist',
       repository_ctx.attr.package],
      quiet=False
  )
  if result.return_code:
    fail("failed to fetch %s: %s" % (repository_ctx.attr.package, result.stderr))
  repository_ctx.template(
    'composer_lib.bzl',
    Label("//tools/build/template:composer_lib.bzl.tpl"),
    substitutions={},
    executable=False
  )
  repository_ctx.template(
    'BUILD',
    Label("//tools/build/template:composer.BUILD.tpl"),
    substitutions={'%{NAME}': repository_ctx.name},
    executable=False
  )
  return None


composer_repository = repository_rule(
  implementation=_composer_repository_impl,
  local=False,
  attrs={
    "package": attr.string(mandatory=True),
    "digest": attr.string(mandatory=True),
    "composer_bin": attr.string(default="composer")
  }
)
