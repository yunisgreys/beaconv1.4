<?php
// qmodsim_analytics.php
// Tailored to BEACON v1.4 (clinic_visits.arrived_at/departed_at, clinic_passes.pass_code)

function beacon_qmodsim_queue_metrics(
    PDO $pdo,
    int $servers = 1,
    int $capacity = 4,
    float $alpha = 1.2,
    float $beta = 0.3,
    float $tHours = 3.0
): array {
    $stmt = $pdo->query("
        SELECT
            COUNT(*) AS total_visits,
            MIN(arrived_at) AS first_arrival,
            MAX(arrived_at) AS last_arrival,
            AVG(EXTRACT(EPOCH FROM (departed_at - arrived_at)) / 3600.0)
                FILTER (WHERE departed_at IS NOT NULL AND departed_at >= arrived_at) AS avg_service_hours
        FROM clinic_visits
    ");
    $row = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];

    $totalVisits = (int)($row['total_visits'] ?? 0);
    $avgServiceHours = (float)($row['avg_service_hours'] ?? 0.5);
    if ($avgServiceHours <= 0) {
        $avgServiceHours = 0.5;
    }

    $spanHours = 24.0;
    if (!empty($row['first_arrival']) && !empty($row['last_arrival'])) {
        $seconds = strtotime((string)$row['last_arrival']) - strtotime((string)$row['first_arrival']);
        if ($seconds > 3600) {
            $spanHours = $seconds / 3600.0;
        }
    }

    $lambda0 = $totalVisits > 0 ? ($totalVisits / $spanHours) : 0.5;
    $nhppLambdaT = $lambda0 + ($alpha * $tHours * exp(-$beta * $tHours));

    $mu = 1.0 / $avgServiceHours;
    $offeredLoadA = $nhppLambdaT / $mu;

    $c = max(1, $servers);
    $k = max($c, $capacity);

    $weights = [0 => 1.0];
    $w = 1.0;
    for ($n = 1; $n <= $k; $n++) {
        $divisor = ($n <= $c) ? (float)$n : (float)$c;
        $w = $w * ($offeredLoadA / $divisor);
        $weights[$n] = $w;
    }

    $totalWeight = array_sum($weights);
    $pDrop = $totalWeight > 0 ? ($weights[$k] / $totalWeight) : 0.0;
    $lambdaEff = $nhppLambdaT * (1.0 - $pDrop);

    $expectedL = 0.0;
    for ($n = 0; $n <= $k; $n++) {
        $pn = $totalWeight > 0 ? ($weights[$n] / $totalWeight) : 0.0;
        $expectedL += $n * $pn;
    }

    $avgTimeInSystemW = $lambdaEff > 0 ? ($expectedL / $lambdaEff) : $avgServiceHours;
    $littlesLawL = $lambdaEff * $avgTimeInSystemW;

    return [
        'total_clinic_visits'    => $totalVisits,
        'lambda_0_per_hr'        => round($lambda0, 4),
        'nhpp_lambda_t_per_hr'   => round($nhppLambdaT, 4),
        'avg_service_hours'      => round($avgServiceHours, 4),
        'mu_per_server_per_hr'   => round($mu, 4),
        'p_drop_bed_exhaustion'  => round($pDrop, 4),
        'lambda_eff_per_hr'      => round($lambdaEff, 4),
        'avg_system_time_W_hr'   => round($avgTimeInSystemW, 4),
        'littles_law_L_students' => round($littlesLawL, 4),
    ];
}

function beacon_qmodsim_entropy(int $length, int $alphabetSize, float $guessesPerSec = 1000.0, float $attackShare = 0.5): array
{
    $entropyBits = $length * (log($alphabetSize) / log(2));
    $keyspace = pow(2.0, $entropyBits);
    $denom = max(1e-9, 2.0 * $guessesPerSec * $attackShare);
    $seconds = $keyspace / $denom;

    return [
        'length'               => $length,
        'alphabet_size'        => $alphabetSize,
        'entropy_bits'         => round($entropyBits, 2),
        'time_to_compromise_s' => $seconds,
        'time_to_compromise_d' => round($seconds / 86400.0, 2),
    ];
}

if (basename(__FILE__) === basename($_SERVER['SCRIPT_FILENAME'] ?? '')) {
    header('Content-Type: application/json');
    $entropy = [
        'otp_6_digit'            => beacon_qmodsim_entropy(6, 10),
        'clinic_pass_8_char'     => beacon_qmodsim_entropy(8, 62),
        'password_10_char'       => beacon_qmodsim_entropy(10, 72),
        'token_16_char'          => beacon_qmodsim_entropy(16, 62),
    ];

    $queue = null;
    $dbUrl = getenv('DATABASE_URL');
    if ($dbUrl) {
        try {
            $u = parse_url($dbUrl);
            $dsn = sprintf(
                'pgsql:host=%s;port=%d;dbname=%s',
                $u['host'] ?? 'localhost',
                $u['port'] ?? 5432,
                ltrim($u['path'] ?? '', '/')
            );
            $pdo = new PDO($dsn, $u['user'] ?? '', $u['pass'] ?? '', [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            ]);
            $pdo->exec("SET timezone TO 'Asia/Manila'");
            $queue = beacon_qmodsim_queue_metrics($pdo);
        } catch (Throwable $e) {
            $queue = ['error' => $e->getMessage()];
        }
    }

    echo json_encode([
        'qmodsim_queue_and_poisson' => $queue,
        'qmodsim_entropy_analysis'  => $entropy,
    ], JSON_PRETTY_PRINT);
}