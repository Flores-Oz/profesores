<?php
// Configuración de conexión
$host = "192.168.1.53";     // IP de tu Raspberry Pi (servidor DB)
$dbname = "proyecto";       // Base de datos
$user = "checha";           // Usuario DB
$pass = "admin1234";        // Contraseña DB

try {
    // Conexión PDO
    $pdo = new PDO("mysql:host=$host;dbname=$dbname;charset=utf8mb4", $user, $pass, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION
    ]);

    // Consulta
    $stmt = $pdo->query("SELECT * FROM profesores");

    // Mostrar resultados en tabla HTML
    echo "<h2>✅ Conectado a la base de datos correctamente</h2>";
    echo "<h3>Listado de profesores</h3>";

    echo "<table border='1' cellpadding='8' cellspacing='0'>";
    echo "<tr style='background:#eee;'>";
    // Encabezados dinámicos
    $firstRow = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($firstRow) {
        foreach (array_keys($firstRow) as $col) {
            echo "<th>" . htmlspecialchars($col) . "</th>";
        }
        echo "</tr>";

        // Primera fila
        echo "<tr>";
        foreach ($firstRow as $val) {
            echo "<td>" . htmlspecialchars($val) . "</td>";
        }
        echo "</tr>";

        // Resto de filas
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            echo "<tr>";
            foreach ($row as $val) {
                echo "<td>" . htmlspecialchars($val) . "</td>";
            }
            echo "</tr>";
        }
    } else {
        echo "<tr><td colspan='5'>No hay registros en la tabla profesores.</td></tr>";
    }

    echo "</table>";

} catch (PDOException $e) {
    echo "<h3 style='color:red;'>❌ Error al conectar o consultar:</h3>";
    echo "<pre>" . htmlspecialchars($e->getMessage()) . "</pre>";
}
?>
