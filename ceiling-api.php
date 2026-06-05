<?php
/* ============================================================
   ceiling-api.php  —  server-side proxy for The Invisible Ceiling
   Upload this next to index.html in public_html.
   Your Anthropic key lives HERE, on the server — never in the page.
   ============================================================ */

header('Content-Type: application/json');

/* --- OPTIONAL hardening: only accept requests from your own site ---
   Uncomment and set your domain to stop strangers hitting this file. */
// $ok_origin = 'https://emhill.com';
// $ref = $_SERVER['HTTP_ORIGIN'] ?? $_SERVER['HTTP_REFERER'] ?? '';
// if (strpos($ref, $ok_origin) !== 0) { http_response_code(403); echo json_encode(["error"=>"Forbidden"]); exit; }

/* --- your key ---
   Best: set ANTHROPIC_API_KEY as an env var in Hostinger (hPanel).
   Fallback: paste it on the next line (and keep this file private). */
$API_KEY = getenv('ANTHROPIC_API_KEY');
if (!$API_KEY) { $API_KEY = 'sk-ant-PASTE-YOUR-KEY-HERE'; }
if (!$API_KEY || strpos($API_KEY, 'PASTE') !== false) {
  http_response_code(500); echo json_encode(["error"=>"API key not set"]); exit;
}

$MODEL = 'claude-sonnet-4-6'; // cheaper/faster option: claude-haiku-4-5-20251001

/* --- read the founder's inputs sent from the page --- */
$in = json_decode(file_get_contents('php://input'), true);
if (!$in) { http_response_code(400); echo json_encode(["error"=>"Bad input"]); exit; }
$current = $in['current'] ?? '';
$desired = $in['desired'] ?? '';
$gap     = $in['gap']     ?? '';
$mult    = $in['mult']    ?? '';
$dur     = $in['dur']     ?? '';
$answers = $in['answers'] ?? '';

/* --- the prompt lives server-side --- */
$system = <<<SYS
You are Dr. Emily Hill, a business identity strategist who sees the pattern beneath the tactics.
Read a founder's open-ended answers and produce a Ceiling Profile.
First classify them into ONE archetype key: controller, technician, prover, hider, protector — based on what their words reveal.
Then sort their words into four bins.
VOICE — write the way Emily talks: short, blunt, direct sentences. Plain everyday words, contractions, the occasional dash. Talk straight to them as "you." NO polished coaching aphorisms, no balanced/parallel triads, no fancy vocabulary (avoid words like "undeniable," "equilibrium," "compounded"). Sound like a sharp friend who sees them clearly — not a brand or a bot. Never call them broken; frame the pattern as protection that once kept them safe. No guarantees. Use their actual words back where you can.
Respond with ONLY valid JSON, no markdown:
{"archetype":"<key>","pattern":"...","protection":"...","cost":"...","lever":"..."}
Each text field 2-3 sentences.
SYS;

$user = "Numbers:\n- Current monthly revenue: $current\n- Target monthly revenue: $desired\n- Gap: $gap ($mult)\n- Trying to move it for: $dur\n\nOpen answers:\n$answers\n\n\"pattern\" should use the gap + duration. \"protection\" names what their archetype is protecting. \"cost\" compounds 1-3 years forward. \"lever\" lands on identity, not strategy.";

$payload = json_encode([
  "model"      => $MODEL,
  "max_tokens" => 1000,
  "system"     => $system,
  "messages"   => [["role"=>"user","content"=>$user]]
]);

/* --- call Anthropic with your key --- */
$ch = curl_init('https://api.anthropic.com/v1/messages');
curl_setopt_array($ch, [
  CURLOPT_RETURNTRANSFER => true,
  CURLOPT_POST           => true,
  CURLOPT_POSTFIELDS     => $payload,
  CURLOPT_HTTPHEADER     => [
    'Content-Type: application/json',
    'x-api-key: ' . $API_KEY,
    'anthropic-version: 2023-06-01'
  ],
  CURLOPT_TIMEOUT => 40
]);
$resp = curl_exec($ch);
if ($resp === false) { http_response_code(502); echo json_encode(["error"=>"Upstream error","detail"=>curl_error($ch)]); curl_close($ch); exit; }
curl_close($ch);

/* --- pull the text out, strip any fences, return the 4-field profile --- */
$data = json_decode($resp, true);
$txt = '';
if (isset($data['content']) && is_array($data['content'])) {
  foreach ($data['content'] as $b) { if (($b['type'] ?? '') === 'text') $txt .= $b['text']; }
}
$txt = trim(preg_replace('/```json|```/', '', $txt));
$profile = json_decode($txt, true);
if (!$profile) { http_response_code(502); echo json_encode(["error"=>"Could not parse profile","raw"=>$txt]); exit; }

echo json_encode($profile);
