<?php
/** Frozen authorship and execution identities through the actual worker lifecycle. */
require __DIR__ . '/delivery-unit.php';
[$job, $store, $server, $worker] = delivery_fixture(['actor_user_id' => 1, 'author_user_id' => 2]);
$result = $worker->process($job);
delivery_check($result['state'] === 'complete' && Contract_Test_Writer::$context['actor_user_id'] === 1 && Contract_Test_Writer::$context['author_user_id'] === 2, 'The selected author and executing user remain distinct.');

[$job, $store, $server, $worker] = delivery_fixture(['actor_user_id' => 1, 'author_user_id' => 2]);
$server->lose = 'received_complete';
$pending = $worker->process($job);
$f = nova_contract_fixture(); $f['connection']['actor_user_id'] = 1; $f['connection']['author_user_id'] = 1;
$worker = delivery_worker($f, $store);
$result = $worker->process(delivery_resume($pending));
delivery_check($result['state'] === 'complete' && Contract_Test_Writer::$context['author_user_id'] === 2, 'A lost acknowledgement and changed setting retain the original author.');

[$job, $store, $server, $worker] = delivery_fixture(['actor_user_id' => 1, 'author_user_id' => 999]);
$result = $worker->process($job);
delivery_check($result['last_error'] === 'publishing_author_unavailable' && Contract_Test_Writer::$apply === 0, 'An unavailable author prevents native mutation.');

[$job, $store, $server, $worker] = delivery_fixture(['actor_user_id' => 1, 'author_user_id' => 2]);
$GLOBALS['revoked_actor'] = 1;
$result = $worker->process($job);
delivery_check($result['last_error'] === 'publishing_user_unavailable' && Contract_Test_Writer::$apply === 0, 'Revoked execution authority is still enforced.');
unset($GLOBALS['revoked_actor']);

[$job, $store, $server, $worker] = delivery_fixture(['actor_user_id' => 1, 'author_user_id' => 1]);
$job['payload']['actor_user_id'] = 2;
$result = $worker->process($job);
delivery_check(Contract_Test_Writer::$context['actor_user_id'] === 2 && Contract_Test_Writer::$context['author_user_id'] === 2, 'A historical frozen actor retains its execution and author identity.');
echo "PASS frozen author attribution and execution authority\n";
