<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Route Path
    |--------------------------------------------------------------------------
    |
    | Passport only serves the OAuth flow of the MCP server. Its default "oauth"
    | prefix collides with the Socialstream login routes (oauth/{provider}).
    | All other options keep the package defaults, e.g. the PASSPORT_PRIVATE_KEY
    | and PASSPORT_PUBLIC_KEY environment variables.
    |
    */

    'path' => 'mcp/oauth',

];
