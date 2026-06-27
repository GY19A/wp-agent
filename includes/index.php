<?php
// Silence is golden. Prevents directory listing if the web server is
// misconfigured to allow it. Plugin internals here are loaded via the
// filesystem, never meant to be requested over HTTP. See includes/.htaccess
// (Apache) and the README "Hardening behind nginx/Caddy" section for
// server-level rules that also cover non-Apache stacks.
