<?php

return [
    'username' => env('MEMYSELFAI_USERNAME', ''),
    'password_hash' => env('MEMYSELFAI_PASSWORD_HASH', ''),
    'mcp_token' => env('MEMYSELFAI_MCP_TOKEN', ''),
    'calendar_include_done' => (bool) env('MEMYSELFAI_CALENDAR_INCLUDE_DONE', false),
];
