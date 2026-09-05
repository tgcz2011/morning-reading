<?php
require_once 'functions.php';
checkAuth();

// 初始化数据库
initDatabase();

// 获取统计周期
$period = isset($_GET['period']) ? $_GET['period'] : 'week';
$valid_periods = ['day', 'week', 'month', 'semester'];
if (!in_array($period, $valid_periods)) {
    $period = 'week';
}

$period_names = [
    'day' => '今日',
    'week' => '本周',
    'month' => '本月',
    'semester' => '本学期'
];

// 获取统计数据（用于表格，包含负分）
$stats_data = getStatistics($period);
// 获取正分统计数据（用于饼状图，不包含负分）- 修复问题2
$positive_stats_data = getPositiveStatistics($period);

global $students;

// 处理数据用于显示（表格数据）
$ranking_data = [];
foreach ($stats_data as $data) {
    $student_id = $data['student_id'];
    $score = isset($data['score']) ? $data['score'] : $data['count'];
    $ranking_data[] = [
        'id' => $student_id,
        'name' => $students[$student_id],
        'score' => $score
    ];
}

// 按分数排序
usort($ranking_data, function($a, $b) {
    return $b['score'] - $a['score'];
});

// 生成饼状图数据 (只使用正分数据) - 修复问题2
$chart_data = [];
$other_count = 0;
$total_positive_score = 0;

// 处理正分数据
$positive_ranking_data = [];
foreach ($positive_stats_data as $data) {
    $student_id = $data['student_id'];
    $score = isset($data['score']) ? $data['score'] : $data['count'];
    $positive_ranking_data[] = [
        'id' => $student_id,
        'name' => $students[$student_id],
        'score' => $score
    ];
    $total_positive_score += $score;
}

// 按分数排序正分数据
usort($positive_ranking_data, function($a, $b) {
    return $b['score'] - $a['score'];
});

// 生成饼状图数据（前10名正分）
for ($i = 0; $i < count($positive_ranking_data); $i++) {
    if ($i < 10) {
        $chart_data[] = [
            'name' => $positive_ranking_data[$i]['name'],
            'score' => $positive_ranking_data[$i]['score']
        ];
    } else {
        $other_count += $positive_ranking_data[$i]['score'];
    }
}
if ($other_count > 0) {
    $chart_data[] = ['name' => '其他', 'score' => $other_count];
}
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>统计页面 - 班级朗读记录系统</title>
    <link rel="stylesheet" href="style.css">
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
</head>
<body>
    <div class="container">
        <div class="header">
            <div class="header-title">
                <h1>朗读记录统计</h1>
                <span class="session-seal"><?php echo $period_names[$period]; ?></span>
            </div>
            <div class="time-info">
                <?php 
                echo date('Y年m月d日 H:i');
                echo ' · 第' . getWeekNumber() . '周';
                ?>
            </div>
        </div>
        
        <div class="nav-bar">
            <a href="index.php" class="nav-btn">记录页面</a>
            <a href="stats.php" class="nav-btn active">统计页面</a>
        </div>
        
        <div class="stats-container">
            <div class="period-selector">
                <button class="period-btn <?php echo $period === 'day' ? 'active' : ''; ?>" 
                        onclick="window.location.href='stats.php?period=day'">今日统计</button>
                <button class="period-btn <?php echo $period === 'week' ? 'active' : ''; ?>" 
                        onclick="window.location.href='stats.php?period=week'">本周统计</button>
                <button class="period-btn <?php echo $period === 'month' ? 'active' : ''; ?>" 
                        onclick="window.location.href='stats.php?period=month'">本月统计</button>
                <button class="period-btn <?php echo $period === 'semester' ? 'active' : ''; ?>" 
                        onclick="window.location.href='stats.php?period=semester'">学期统计</button>
            </div>
            
            <h2 class="stats-title">
                <?php echo $period_names[$period] . '朗读记录排行榜'; ?>
            </h2>
            
            <div class="stats-note">
                <strong>统计说明：</strong>
                <span>表格显示净分数（加分-扣分），饼状图只显示正分次数</span>
            </div>
            
            <table class="ranking-table">
                <thead>
                    <tr>
                        <th width="10%">排名</th>
                        <th width="12%">学号</th>
                        <th width="25%">姓名</th>
                        <th width="18%">净分数</th>
                        <th width="35%">百分比</th>
                    </tr>
                </thead>
                <tbody>
                    <?php
                    $total_score = 0;
                    foreach ($ranking_data as $student) {
                        $total_score += max(0, $student['score']); // 只计算正分用于百分比
                    }
                    
                    for ($i = 0; $i < count($ranking_data); $i++) {
                        $student = $ranking_data[$i];
                        $percentage = $total_score > 0 ? round((max(0, $student['score']) / $total_score) * 100, 1) : 0;
                        $rank = $i + 1;
                        
                        // 排名徽章
                        $rank_badge = '<span class="rank-plain">' . $rank . '</span>';
                        if ($rank === 1) $rank_badge = '<span class="rank-medal rank-1">' . $rank . '</span>';
                        elseif ($rank === 2) $rank_badge = '<span class="rank-medal rank-2">' . $rank . '</span>';
                        elseif ($rank === 3) $rank_badge = '<span class="rank-medal rank-3">' . $rank . '</span>';
                        
                        // 分数样式
                        if ($student['score'] < 0) {
                            $score_display = '<span class="score-negative">' . $student['score'] . '</span>';
                            $bar_class = 'pct-bar neg';
                        } elseif ($student['score'] > 0) {
                            $score_display = '<span class="score-positive">' . $student['score'] . '</span>';
                            $bar_class = 'pct-bar';
                        } else {
                            $score_display = '<span class="score-zero">' . $student['score'] . '</span>';
                            $bar_class = 'pct-bar';
                        }
                        
                        echo "
                        <tr>
                            <td>{$rank_badge}</td>
                            <td>{$student['id']}</td>
                            <td>{$student['name']}</td>
                            <td>{$score_display}</td>
                            <td>
                                <div class='pct-text'>{$percentage}%</div>
                                <div class='{$bar_class}'><i style='width:{$percentage}%'></i></div>
                            </td>
                        </tr>";
                    }
                    
                    if (empty($ranking_data)) {
                        echo '<tr><td colspan="5" style="text-align: center;">暂无数据</td></tr>';
                    }
                    ?>
                </tbody>
            </table>
            
            <?php if (!empty($chart_data) && $total_positive_score > 0): ?>
            <div class="chart-container">
                <h3>正分分布饼状图（不含负分）</h3>
                <div class="chart-wrap">
                    <canvas id="statsChart" width="400" height="400"></canvas>
                </div>
            </div>
            
            <script>
                const ctx = document.getElementById('statsChart').getContext('2d');
                const chartData = {
                    labels: <?php echo json_encode(array_column($chart_data, 'name')); ?>,
                    datasets: [{
                        data: <?php echo json_encode(array_column($chart_data, 'score')); ?>,
                        backgroundColor: [
                            '#C43A2C', '#2F6B4F', '#C9971C', '#3E7C7C', '#8E5A7C',
                            '#7A7A3C', '#A05A3C', '#B07A5A', '#5A6B7A', '#8A8272',
                            '#B8AE95'
                        ]
                    }]
                };
                
                new Chart(ctx, {
                    type: 'pie',
                    data: chartData,
                    options: {
                        responsive: true,
                        plugins: {
                            legend: {
                                position: 'right',
                            },
                            tooltip: {
                                callbacks: {
                                    label: function(context) {
                                        const label = context.label || '';
                                        const value = context.raw || 0;
                                        const total = context.dataset.data.reduce((a, b) => a + b, 0);
                                        const percentage = Math.round((value / total) * 100);
                                        return `${label}: ${value}次 (${percentage}%)`;
                                    }
                                }
                            }
                        }
                    }
                });
            </script>
            <?php elseif ($total_positive_score == 0): ?>
            <div class="chart-container">
                <h3>正分分布饼状图</h3>
                <p class="chart-empty">暂无正分数据</p>
            </div>
            <?php endif; ?>
        </div>
    </div>
</body>
</html>