<?php

return [
    // Enable only after a merchant opts into agent-required routing. When off,
    // unassigned channels retain their existing business-level AI behavior.
    'fail_closed_no_agent' => (bool) env('AGENT_ROUTING_FAIL_CLOSED', false),
];
