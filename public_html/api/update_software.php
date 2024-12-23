<?php

require __DIR__ . "/../../bootstrapper.php";
header("Content-Type: application/json");

if ($_SERVER["REQUEST_METHOD"] !== "POST") {
    echo json_encode(["error" => $lang["method_not_allowed"]]);
    http_response_code(405);
    exit();
}

if (!in_array("admin", $permissions)) {
    echo json_encode(["error" => $lang["insufficient_permissions"]]);
    http_response_code(403);
    exit();
}

$currentVersion = $version;
$branch = str_contains($currentVersion, "devel") ? "devel" : "master";
$latestStableVersionFile = "https://raw.githubusercontent.com/5ynchrogazer/LibreBooru-Extras/refs/heads/master/latest_stable.txt";
$latestDevelVersionFile = "https://raw.githubusercontent.com/5ynchrogazer/LibreBooru-Extras/refs/heads/master/latest_devel.txt";
$stableVersionsFile = "https://raw.githubusercontent.com/5ynchrogazer/LibreBooru-Extras/refs/heads/master/versions_stable.json";
$develVersionsFile = "https://raw.githubusercontent.com/5ynchrogazer/LibreBooru-Extras/refs/heads/master/versions_devel.json";

$latestStableVersion = file_get_contents($latestStableVersionFile);
$latestDevelVersion = file_get_contents($latestDevelVersionFile);
$versions = [];
$versions["stable"] = json_decode(file_get_contents($stableVersionsFile), true);
$versions["devel"] = json_decode(file_get_contents($develVersionsFile), true);

// Check where the current version is
// ["0.1.0-devel", "0.1.1-devel"]
$updateOrder = [];
$versions = $versions[$branch];
foreach ($versions as $vkey => $ver) {
    if ($ver === $currentVersion) {
        $updateOrder = array_slice($versions, $vkey + 1);
        break;
    }
}

$sqlPath = __DIR__ . "/../../__init/.tmp/sql";
if (!file_exists($sqlPath)) {
    mkdir($sqlPath, 0777, true);
}

foreach ($updateOrder as $upOrder) {
    $sqlFile = "https://raw.githubusercontent.com/5ynchrogazer/LibreBooru-Extras/refs/heads/master/sql/update-{$upOrder}.sql";
    // Check if file exists on GitHub
    if (file_get_contents($sqlFile)) {
        $sqlData = file_get_contents($sqlFile);
        $sqlPath = $sqlPath . "/update-{$upOrder}.sql";
        file_put_contents($sqlPath, $sqlData);
        echo "<span style='color:blue'>[INFO] SQL:</span> " . $sqlPath . "<br>";

        $sql = file_get_contents($sqlPath);
        $sqlStatements = explode(';', $sql);
        foreach ($sqlStatements as $statement) {
            // Trim whitespace and skip empty statements
            $statement = trim($statement);
            if (empty($statement)) {
                continue;
            }

            try {
                if ($conn->query($statement)) {
                    echo "<span style='color:green'>[DEBUG] Successfully executed:</span> $statement<br>";
                } else {
                    echo "<span style='color:red'>[DEBUG] Error executing:</span> $statement<br><span style='color:red'>[DEBUG] Error:</span> {$conn->error}<br>";
                }
            } catch (mysqli_sql_exception $e) {
                echo "<span style='color:red'>[DEBUG] Exception on statement:</span> $statement<br><span style='color:red'>[DEBUG] Error:</span> " . $e->getMessage() . "<br>";
            }
        }
    }
}

$requiresUpdates = [];
foreach ($updateOrder as $update) {
    $updateFile = "https://raw.githubusercontent.com/5ynchrogazer/LibreBooru-Extras/refs/heads/master/update/{$update}.json";
    $updateData = json_decode(file_get_contents($updateFile), true);
    $requiresUpdates = array_merge($requiresUpdates, $updateData);
}

if (empty($requiresUpdates)) {
    echo "<span style='color:blue'>[INFO] Info:</span> " . $lang["no_updates_available"];
    http_response_code(400);
    exit();
}

$tmpPath = __DIR__ . "/../../__init/.tmp/update";
$tmpPathOld = __DIR__ . "/../../__init/.tmp/update_old";
if (!file_exists($tmpPath)) {
    mkdir($tmpPath, 0777, true);
}
if (!file_exists($tmpPathOld)) {
    mkdir($tmpPathOld, 0777, true);
}

foreach ($requiresUpdates as $update) {
    $currentUpdateFile = __DIR__ . "/../../" . $update;
    $updateFile = "https://raw.githubusercontent.com/5ynchrogazer/LibreBooru/refs/heads/" . $branch . "/" . $update;
    if (file_exists($currentUpdateFile)) {
        $currentUpdateData = file_get_contents($currentUpdateFile);
        $currentUpdatePath = $tmpPathOld . "/" . $update;
        $currentUpdateDir = dirname($currentUpdatePath);

        if (!file_exists($currentUpdateDir)) {
            mkdir($currentUpdateDir, 0777, true);
        }

        file_put_contents($currentUpdatePath, $currentUpdateData);
    }

    $updateData = file_get_contents($updateFile);
    $updatePath = $tmpPath . "/" . $update;
    $updateDir = dirname($updatePath);

    if (!file_exists($updateDir)) {
        mkdir($updateDir, 0777, true);
    }

    file_put_contents($updatePath, $updateData);

    $diff = shell_exec("diff -u " . $currentUpdatePath . " " . $updatePath);

    if ($diff) {
        $updatePath = __DIR__ . "/../../" . $update;
        $updateDir = dirname($updatePath);

        if (!file_exists($updateDir)) {
            mkdir($updateDir, 0777, true);
        }

        echo "<span style='color:green'>[INFO] Updating:</span> " . $update . "<br>";
        file_put_contents($updatePath, $updateData);
    } else {
        echo "<span style='color:blue'>[INFO] No changes:</span> " . $update . "<br>";
    }
}

$newVersion = trim($branch === "devel" ? $latestDevelVersion : $latestStableVersion);
$versionFile = __DIR__ . "/../../version";
file_put_contents($versionFile, $newVersion);

if (file_exists($tmpPathOld)) {
    shell_exec("rm -rf " . $tmpPathOld);
}
if (file_exists($tmpPath)) {
    shell_exec("rm -rf " . $tmpPath);
}
if (file_exists($sqlPath)) {
    shell_exec("rm -rf " . $sqlPath);
}
