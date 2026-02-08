<?php
/**
 * Statistics Viewer
 * View analytics data collected from the application
 */

// Configuration - get stats directory from environment variable or use default
$statsDir = getenv('STATS_DIR');
if ($statsDir === false || $statsDir === '') {
    $statsDir = '/working/plavenky-stats/';
}
$statsDir = rtrim($statsDir, '/');

// Get all stats files
function getStatsFiles($dir) {
    if (!is_dir($dir)) {
        return [];
    }
    $files = glob($dir . '/stats-*.jsonl');
    rsort($files); // Sort descending (newest first)
    return $files;
}

// Load all events from files
function loadAllEvents($files) {
    $events = [];
    foreach ($files as $file) {
        $lines = file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        foreach ($lines as $line) {
            $event = json_decode($line, true);
            if ($event) {
                $events[] = $event;
            }
        }
    }
    return $events;
}

// Get unique instances
function getInstances($events) {
    $instances = [];
    foreach ($events as $event) {
        $id = $event['instanceId'];
        if (!isset($instances[$id])) {
            $instances[$id] = [
                'id' => $id,
                'firstSeen' => $event['timestamp'],
                'lastSeen' => $event['timestamp'],
                'eventCount' => 0,
                'events' => []
            ];
        }
        $instances[$id]['eventCount']++;
        $instances[$id]['lastSeen'] = max($instances[$id]['lastSeen'], $event['timestamp']);
        $instances[$id]['firstSeen'] = min($instances[$id]['firstSeen'], $event['timestamp']);
        
        // Count events by type
        $eventType = $event['event'];
        if (!isset($instances[$id]['events'][$eventType])) {
            $instances[$id]['events'][$eventType] = 0;
        }
        $instances[$id]['events'][$eventType]++;
    }
    return $instances;
}

// Get event statistics
function getEventStats($events) {
    $stats = [];
    foreach ($events as $event) {
        $type = $event['event'];
        if (!isset($stats[$type])) {
            $stats[$type] = 0;
        }
        $stats[$type]++;
    }
    arsort($stats);
    return $stats;
}

// Get monthly statistics
function getMonthlyStats($events) {
    $monthly = [];
    foreach ($events as $event) {
        $month = substr($event['timestamp'], 0, 7); // YYYY-MM
        if (!isset($monthly[$month])) {
            $monthly[$month] = [
                'total' => 0,
                'instances' => [],
                'events' => []
            ];
        }
        $monthly[$month]['total']++;
        $monthly[$month]['instances'][$event['instanceId']] = true;
        
        $eventType = $event['event'];
        if (!isset($monthly[$month]['events'][$eventType])) {
            $monthly[$month]['events'][$eventType] = 0;
        }
        $monthly[$month]['events'][$eventType]++;
    }
    
    // Convert instance sets to counts
    foreach ($monthly as $month => $data) {
        $monthly[$month]['uniqueInstances'] = count($data['instances']);
        unset($monthly[$month]['instances']);
    }
    
    krsort($monthly);
    return $monthly;
}

// Handle instance selection
$selectedInstance = isset($_GET['instance']) ? $_GET['instance'] : null;
$selectedMonth = isset($_GET['month']) ? $_GET['month'] : null;

// Load data
$files = getStatsFiles($statsDir);
$allEvents = loadAllEvents($files);
$instances = getInstances($allEvents);
$eventStats = getEventStats($allEvents);
$monthlyStats = getMonthlyStats($allEvents);

// Filter events if instance is selected
$filteredEvents = $allEvents;
if ($selectedInstance && isset($instances[$selectedInstance])) {
    $filteredEvents = array_filter($allEvents, function($e) use ($selectedInstance) {
        return $e['instanceId'] === $selectedInstance;
    });
    $eventStats = getEventStats($filteredEvents);
}

// Filter by month if selected
if ($selectedMonth) {
    $filteredEvents = array_filter($filteredEvents, function($e) use ($selectedMonth) {
        return strpos($e['timestamp'], $selectedMonth) === 0;
    });
    $eventStats = getEventStats($filteredEvents);
}

?>
<!DOCTYPE html>
<html lang="cs">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Statistiky aplikace Evidence plavání</title>
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }
        
        body {
            font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, "Helvetica Neue", Arial, sans-serif;
            background: #f5f7fa;
            color: #333;
            padding: 20px;
        }
        
        .container {
            max-width: 1400px;
            margin: 0 auto;
        }
        
        h1 {
            color: #2563eb;
            margin-bottom: 30px;
            font-size: 32px;
        }
        
        h2 {
            color: #1f2937;
            margin: 30px 0 15px 0;
            font-size: 24px;
            border-bottom: 2px solid #2563eb;
            padding-bottom: 8px;
        }
        
        h3 {
            color: #374151;
            margin: 20px 0 10px 0;
            font-size: 18px;
        }
        
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
            gap: 20px;
            margin-bottom: 30px;
        }
        
        .stat-card {
            background: white;
            padding: 20px;
            border-radius: 12px;
            box-shadow: 0 1px 3px rgba(0,0,0,0.1);
        }
        
        .stat-card h3 {
            font-size: 14px;
            color: #6b7280;
            margin: 0 0 10px 0;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }
        
        .stat-value {
            font-size: 36px;
            font-weight: bold;
            color: #2563eb;
        }
        
        table {
            width: 100%;
            background: white;
            border-radius: 12px;
            overflow: hidden;
            box-shadow: 0 1px 3px rgba(0,0,0,0.1);
            margin-bottom: 30px;
        }
        
        th, td {
            padding: 12px 16px;
            text-align: left;
        }
        
        th {
            background: #f9fafb;
            font-weight: 600;
            color: #374151;
            border-bottom: 2px solid #e5e7eb;
        }
        
        tr:not(:last-child) td {
            border-bottom: 1px solid #f3f4f6;
        }
        
        tr:hover {
            background: #f9fafb;
        }
        
        .instance-id {
            font-family: 'Courier New', monospace;
            background: #f3f4f6;
            padding: 4px 8px;
            border-radius: 4px;
            font-size: 12px;
        }
        
        .filter-bar {
            background: white;
            padding: 20px;
            border-radius: 12px;
            box-shadow: 0 1px 3px rgba(0,0,0,0.1);
            margin-bottom: 30px;
            display: flex;
            gap: 15px;
            flex-wrap: wrap;
            align-items: center;
        }
        
        .filter-bar label {
            font-weight: 600;
            color: #374151;
        }
        
        .filter-bar select {
            padding: 8px 12px;
            border: 2px solid #e5e7eb;
            border-radius: 6px;
            font-size: 14px;
            cursor: pointer;
        }
        
        .filter-bar select:focus {
            outline: none;
            border-color: #2563eb;
        }
        
        .btn {
            padding: 8px 16px;
            background: #2563eb;
            color: white;
            border: none;
            border-radius: 6px;
            cursor: pointer;
            text-decoration: none;
            display: inline-block;
            font-size: 14px;
            font-weight: 500;
        }
        
        .btn:hover {
            background: #1d4ed8;
        }
        
        .btn-secondary {
            background: #6b7280;
        }
        
        .btn-secondary:hover {
            background: #4b5563;
        }
        
        .empty-state {
            text-align: center;
            padding: 60px 20px;
            color: #6b7280;
        }
        
        .event-badge {
            display: inline-block;
            padding: 4px 10px;
            background: #dbeafe;
            color: #1e40af;
            border-radius: 12px;
            font-size: 12px;
            font-weight: 500;
            margin: 2px;
        }
        
        .timeline {
            position: relative;
            padding-left: 30px;
            margin-top: 20px;
        }
        
        .timeline-item {
            position: relative;
            padding-bottom: 20px;
        }
        
        .timeline-item::before {
            content: '';
            position: absolute;
            left: -23px;
            top: 5px;
            width: 10px;
            height: 10px;
            background: #2563eb;
            border-radius: 50%;
        }
        
        .timeline-item::after {
            content: '';
            position: absolute;
            left: -19px;
            top: 15px;
            width: 2px;
            height: 100%;
            background: #e5e7eb;
        }
        
        .timeline-item:last-child::after {
            display: none;
        }
        
        .timeline-content {
            background: white;
            padding: 15px;
            border-radius: 8px;
            box-shadow: 0 1px 2px rgba(0,0,0,0.05);
        }
        
        .timeline-time {
            color: #6b7280;
            font-size: 12px;
            margin-bottom: 5px;
        }
        
        .timeline-event {
            font-weight: 600;
            color: #1f2937;
            margin-bottom: 5px;
        }
        
        .timeline-data {
            color: #6b7280;
            font-size: 13px;
        }
    </style>
</head>
<body>
    <div class="container">
        <h1>📊 Statistiky Evidence plavání</h1>
        
        <?php if (empty($allEvents)): ?>
            <div class="empty-state">
                <h3>Zatím nejsou žádná data</h3>
                <p>Statistiky se zobrazí po prvním použití aplikace.</p>
            </div>
        <?php else: ?>
            
            <!-- Filter Bar -->
            <div class="filter-bar">
                <label>Filtr:</label>
                <select onchange="location.href='?instance=' + this.value + '<?php echo $selectedMonth ? '&month=' . $selectedMonth : ''; ?>'">
                    <option value="">Všechny instance (<?php echo count($instances); ?>)</option>
                    <?php foreach ($instances as $inst): ?>
                        <option value="<?php echo htmlspecialchars($inst['id']); ?>" <?php echo $selectedInstance === $inst['id'] ? 'selected' : ''; ?>>
                            <?php echo substr($inst['id'], 0, 8); ?>... (<?php echo $inst['eventCount']; ?> událostí)
                        </option>
                    <?php endforeach; ?>
                </select>
                
                <select onchange="location.href='?month=' + this.value + '<?php echo $selectedInstance ? '&instance=' . $selectedInstance : ''; ?>'">
                    <option value="">Všechny měsíce</option>
                    <?php foreach (array_keys($monthlyStats) as $month): ?>
                        <option value="<?php echo $month; ?>" <?php echo $selectedMonth === $month ? 'selected' : ''; ?>>
                            <?php echo $month; ?>
                        </option>
                    <?php endforeach; ?>
                </select>
                
                <?php if ($selectedInstance || $selectedMonth): ?>
                    <a href="?" class="btn btn-secondary">Zrušit filtry</a>
                <?php endif; ?>
            </div>
            
            <!-- Overview Stats -->
            <h2>Přehled</h2>
            <div class="stats-grid">
                <div class="stat-card">
                    <h3>Celkem událostí</h3>
                    <div class="stat-value"><?php echo count($filteredEvents); ?></div>
                </div>
                <div class="stat-card">
                    <h3>Počet instancí</h3>
                    <div class="stat-value"><?php echo count($instances); ?></div>
                </div>
                <div class="stat-card">
                    <h3>Typy událostí</h3>
                    <div class="stat-value"><?php echo count($eventStats); ?></div>
                </div>
                <div class="stat-card">
                    <h3>Datové soubory</h3>
                    <div class="stat-value"><?php echo count($files); ?></div>
                </div>
            </div>
            
            <!-- Event Statistics -->
            <h2>Statistiky událostí</h2>
            <table>
                <thead>
                    <tr>
                        <th>Událost</th>
                        <th>Počet</th>
                        <th>Podíl</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($eventStats as $event => $count): ?>
                        <tr>
                            <td><span class="event-badge"><?php echo htmlspecialchars($event); ?></span></td>
                            <td><?php echo $count; ?></td>
                            <td><?php echo round(($count / count($filteredEvents)) * 100, 1); ?>%</td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
            
            <?php if (!$selectedInstance): ?>
                <!-- Monthly Statistics -->
                <h2>Měsíční přehled</h2>
                <table>
                    <thead>
                        <tr>
                            <th>Měsíc</th>
                            <th>Události</th>
                            <th>Aktivní instance</th>
                            <th>Akce</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($monthlyStats as $month => $stats): ?>
                            <tr>
                                <td><?php echo $month; ?></td>
                                <td><?php echo $stats['total']; ?></td>
                                <td><?php echo $stats['uniqueInstances']; ?></td>
                                <td><a href="?month=<?php echo $month; ?>" class="btn">Zobrazit</a></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
                
                <!-- Instance List -->
                <h2>Seznam instancí</h2>
                <table>
                    <thead>
                        <tr>
                            <th>ID instance</th>
                            <th>První použití</th>
                            <th>Poslední aktivita</th>
                            <th>Události</th>
                            <th>Akce</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($instances as $inst): ?>
                            <tr>
                                <td><span class="instance-id"><?php echo htmlspecialchars($inst['id']); ?></span></td>
                                <td><?php echo date('d.m.Y H:i', strtotime($inst['firstSeen'])); ?></td>
                                <td><?php echo date('d.m.Y H:i', strtotime($inst['lastSeen'])); ?></td>
                                <td><?php echo $inst['eventCount']; ?></td>
                                <td><a href="?instance=<?php echo urlencode($inst['id']); ?>" class="btn">Detail</a></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php else: ?>
                <!-- Instance Detail -->
                <?php $inst = $instances[$selectedInstance]; ?>
                <h2>Detail instance</h2>
                <div class="stats-grid">
                    <div class="stat-card">
                        <h3>ID instance</h3>
                        <div style="font-size: 14px; font-family: monospace; word-break: break-all; color: #374151;">
                            <?php echo htmlspecialchars($inst['id']); ?>
                        </div>
                    </div>
                    <div class="stat-card">
                        <h3>První použití</h3>
                        <div style="font-size: 18px; color: #374151;">
                            <?php echo date('d.m.Y H:i', strtotime($inst['firstSeen'])); ?>
                        </div>
                    </div>
                    <div class="stat-card">
                        <h3>Poslední aktivita</h3>
                        <div style="font-size: 18px; color: #374151;">
                            <?php echo date('d.m.Y H:i', strtotime($inst['lastSeen'])); ?>
                        </div>
                    </div>
                    <div class="stat-card">
                        <h3>Celkem událostí</h3>
                        <div class="stat-value"><?php echo $inst['eventCount']; ?></div>
                    </div>
                </div>
                
                <h3>Události podle typu</h3>
                <div style="margin-bottom: 30px;">
                    <?php foreach ($inst['events'] as $eventType => $eventCount): ?>
                        <span class="event-badge"><?php echo htmlspecialchars($eventType); ?>: <?php echo $eventCount; ?></span>
                    <?php endforeach; ?>
                </div>
                
                <h3>Časová osa posledních 50 událostí</h3>
                <div class="timeline">
                    <?php 
                    $instanceEvents = array_filter($allEvents, function($e) use ($selectedInstance) {
                        return $e['instanceId'] === $selectedInstance;
                    });
                    usort($instanceEvents, function($a, $b) {
                        return strcmp($b['timestamp'], $a['timestamp']);
                    });
                    $recentEvents = array_slice($instanceEvents, 0, 50);
                    ?>
                    <?php foreach ($recentEvents as $event): ?>
                        <div class="timeline-item">
                            <div class="timeline-content">
                                <div class="timeline-time">
                                    <?php echo date('d.m.Y H:i:s', strtotime($event['timestamp'])); ?>
                                </div>
                                <div class="timeline-event">
                                    <?php echo htmlspecialchars($event['event']); ?>
                                </div>
                                <?php if (isset($event['data']) && !empty($event['data'])): ?>
                                    <div class="timeline-data">
                                        <?php echo htmlspecialchars(json_encode($event['data'], JSON_UNESCAPED_UNICODE)); ?>
                                    </div>
                                <?php endif; ?>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
            
        <?php endif; ?>
    </div>
</body>
</html>
