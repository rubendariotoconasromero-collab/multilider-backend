<?php

use GuzzleHttp\Handler\CurlMultiHandler;

define('F5_ACCEPTANCE_HELPERS_ONLY', true);
require __DIR__.'/PhaseFiveAcceptance.php';

try {
    $transport = new CurlMultiHandler(['select_timeout' => 0.1]);
    foreach ([true, false] as $local) {
        $client = endpointF5($local ? 'PHASE5_API_LOCAL' : 'PHASE5_API');
        $token = loginF5();
        $group = 'F5PERFIL'.substr(str_replace('-', '', uuidF5()), 0, 8);
        $fixtures = [];
        for ($i = 0; $i < 2; $i++) {
            $input = operationF5() + ['ci' => $group.'-'.$i, 'lugar_expedicion' => 'LP',
                'nombre_completo' => "Cliente $group $i", 'correo' => strtolower("$group-$i@example.test"),
                'telefono' => '70000000', 'direccion' => 'Ficticia', 'estado' => $i === 0];
            [$status, $created] = callF5('POST', 'clientes', $input);
            checkF5(in_array($status, [200, 201, 202], true), "Could not create profile fixture ($status).");
            if ($status === 202) {
                $op = waitF5($input['operacion_id']);
                checkF5($op['state'] === 'Completada', 'Profile operation did not complete.');
                $fixtures[] = $op['resource_id'];
            } else {
                $fixtures[] = $created['data']['id'] ?? $created['cliente']['id'] ?? null;
            }
            [$status, $found] = callF5('GET', 'clientes?search='.rawurlencode($input['correo']));
            checkF5($status === 200 && count($found['data']) === 1 && $found['data'][0]['ci'] === $input['ci'], 'Email search returned a different profile.');
            $fixtures[$i] = $found['data'][0]['id'];
        }
        [$status, $page1] = callF5('GET', "clientes?search=$group&per_page=1&page=1");
        [$status2, $page2] = callF5('GET', "clientes?search=$group&per_page=1&page=2");
        checkF5($status === 200 && $status2 === 200 && count($page1['data']) === 1 && count($page2['data']) === 1
            && $page1['data'][0]['id'] !== $page2['data'][0]['id'], 'Customer pagination repeated or omitted a matching profile.');
        [$status, $selectors] = callF5('GET', 'comercial/opciones');
        checkF5($status === 200 && in_array($fixtures[0], array_column($selectors['clientes'], 'id'), true)
            && ! in_array($fixtures[1], array_column($selectors['clientes'], 'id'), true), 'Inactive customer is selectable for sale.');
        foreach ([
            ['ci' => str_repeat('1', 51)], ['ci' => $group.'-0'], ['correo' => 'not-an-email'],
            ['lugar_expedicion' => str_repeat('L', 11)], ['telefono' => str_repeat('7', 51)],
        ] as $change) {
            $body = $change + operationF5() + ['ci' => $group.'-new', 'correo' => strtolower("$group-new@example.test"),
                'lugar_expedicion' => 'LP', 'nombre_completo' => 'Perfil inválido de prueba'];
            [$status] = callF5('POST', 'clientes', $body);
            checkF5($status === 422, "Invalid customer field not rejected (".array_key_first($change)." $status).");
        }
        $advisers = [];
        for ($i = 0; $i < 2; $i++) {
            $email = strtolower("asesor-$group-$i@example.test");
            $input = operationF5() + ['nombre_completo' => "Asesor $group $i", 'correo' => $email,
                'password' => 'Adviser-fixture-42!', 'porcentaje_comision' => 2.75, 'estado' => $i === 0,
                'telefono' => '70000000', 'direccion' => 'Ficticia'];
            [$status] = callF5('POST', 'asesores', $input);
            checkF5(in_array($status, [200, 201, 202], true), "Could not create adviser fixture ($status).");
            if ($status === 202) {
                checkF5(waitF5($input['operacion_id'])['state'] === 'Completada', 'Adviser operation did not complete.');
            }
            [$status, $found] = callF5('GET', 'asesores?search='.rawurlencode($email));
            checkF5($status === 200 && count($found['data']) === 1
                && (float) $found['data'][0]['porcentaje_comision'] === 2.75, 'Adviser email search returned another profile.');
            $advisers[] = $found['data'][0]['id'];
        }
        [$status, $page1] = callF5('GET', "asesores?search=$group&per_page=1&page=1");
        [$status2, $page2] = callF5('GET', "asesores?search=$group&per_page=1&page=2");
        checkF5($status === 200 && $status2 === 200 && count($page1['data']) === 1 && count($page2['data']) === 1
            && $page1['data'][0]['id'] !== $page2['data'][0]['id'], 'Adviser pagination repeated or omitted a profile.');
        [$status, $selectors] = callF5('GET', 'comercial/opciones');
        checkF5($status === 200 && in_array($advisers[0], array_column($selectors['asesores'], 'id'), true)
            && ! in_array($advisers[1], array_column($selectors['asesores'], 'id'), true), 'Inactive adviser is selectable for sale.');
        [$status] = callF5('POST', 'asesores', operationF5() + ['nombre_completo' => 'Asesor inválido',
            'correo' => strtolower("invalid-$group@example.test"), 'password' => 'Adviser-fixture-42!', 'porcentaje_comision' => 100.01]);
        checkF5($status === 422, "Adviser accepted commission beyond 100% ($status).");
    }
    echo "F5-C08: $checks checks across local and remote profile search, limits, active selector and pagination.\n";
} catch (Throwable $error) {
    fwrite(STDERR, $error->getMessage()."\n");
    exit(1);
}
