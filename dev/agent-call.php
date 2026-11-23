<?php
// Dev/test: one agent operation as root over its socket, waiting for a job.
//   php agent-call.php <op> ['{"json":"params"}']
// Prints the output as JSON; exits 1 with the agent's message on failure.
function call(string $op, array $params): array {
    $c = @stream_socket_client('unix:///run/panel-agent.sock', $errno, $err, 5);
    if (! $c) { fwrite(STDERR, "agent: $err\n"); exit(2); }
    stream_set_timeout($c, 3600);
    fwrite($c, json_encode(['op' => $op, 'params' => (object) $params])."\n");
    $line = fgets($c);
    fclose($c);
    $r = json_decode((string) $line, true);
    if (! ($r['ok'] ?? false)) { fwrite(STDERR, ($r['error']['message'] ?? 'no answer')."\n"); exit(1); }
    return $r['output'] ?? [];
}
$op = $argv[1] ?? '';
$params = isset($argv[2]) ? json_decode($argv[2], true, flags: JSON_THROW_ON_ERROR) : [];
$out = call($op, $params);
if (isset($out['job_id']) && count($out) === 1) {
    for (;;) {
        sleep(2);
        $s = call('job.status', ['id' => $out['job_id']]);
        if ($s['status'] === 'done') { $out = $s['output'] ?? []; break; }
        if ($s['status'] === 'failed') { fwrite(STDERR, $s['error']."\n"); exit(1); }
    }
}
echo json_encode($out, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), "\n";
