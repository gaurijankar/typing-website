<?php
// Enable error reporting for debugging
error_reporting(E_ALL);
ini_set('display_errors', 1);

// Include the database connection
include 'db_connection.php'; // Ensure the path to db_connection.php is correct

// Start the session to get user ID
session_start();
if (!isset($_SESSION['user_id'])) {
    die("User not logged in");
}

$user_id = $_SESSION['user_id'];

// Check database connection
if (!$connection) {
    die("Database connection failed: " . mysqli_connect_error());
}

// ***** NEW: Check Subscription Status *****
// Query to determine if the user is subscribed (i.e. has at least one successful subscription)
$subscriptionQuery = "SELECT COUNT(*) AS subscription_count 
                      FROM subscriptions 
                      WHERE user_id = ? AND payment_status = 'success'";
$stmtSub = $connection->prepare($subscriptionQuery);
$stmtSub->bind_param("i", $user_id);
$stmtSub->execute();
$resultSub = $stmtSub->get_result();
$subData = $resultSub->fetch_assoc();
$isSubscribed = ($subData['subscription_count'] > 0);
$stmtSub->close();

// Query to fetch typing stats for the current user
$statsQuery = "SELECT wpm, DATE_FORMAT(test_date, '%d %b %Y') AS formatted_date 
               FROM typing_stats 
               WHERE user_id = ? 
               ORDER BY test_date ASC";
$stmt = $connection->prepare($statsQuery);
$stmt->bind_param("i", $user_id);
$stmt->execute();
$statsResult = $stmt->get_result();

$chartData = [];
$chartLabels = [];

while ($row = $statsResult->fetch_assoc()) {
    $chartData[] = (int)$row['wpm'];
    $chartLabels[] = $row['formatted_date'];
}

$stmt->close();

// Query to fetch top users for the leaderboard
$leaderboardQuery = "
    SELECT u.username, MAX(ts.wpm) AS highest_wpm
    FROM user u
    JOIN typing_stats ts ON u.id = ts.user_id
    GROUP BY u.id
    ORDER BY highest_wpm DESC
    LIMIT 5;
";
$leaderboardResult = $connection->query($leaderboardQuery);

$leaderboardData = [];
if ($leaderboardResult) {
    while ($row = $leaderboardResult->fetch_assoc()) {
        $leaderboardData[] = $row;
    }
} else {
    die("Leaderboard query failed: " . $connection->error);
}

// Close the database connection
$connection->close();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>User Dashboard</title>
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <style>
        /* General Styling */
        body {
            font-family: Arial, sans-serif;
            margin: 0;
            padding: 0;
            background: linear-gradient(to bottom right, #f0f8ff, #e6e6fa);
        }

        /* Header Styling */
        header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            background-color: rgb(173, 186, 205);
            padding: 10px 20px;
            box-shadow: 0 2px 4px rgba(0, 0, 0, 0.1);
        }
        .header-left {
            font-size: 18px;
            font-weight: bold;
            color: black;
        }
        .nav-middle {
            flex-grow: 1;
            display: flex;
            justify-content: center;
            gap: 20px;
        }
        .nav-middle a {
            text-decoration: none;
            color: black;
            font-weight: bold;
            font-size: 16px;
        }
        .nav-middle a:hover {
            text-decoration: underline;
        }
        .user-box a {
            text-decoration: none;
            color: black;
            font-weight: bold;
        }
        .user-box a:hover {
            text-decoration: underline;
        }

        /* Main Section */
        main {
            text-align: center;
            padding: 20px;
        }
        h1, h2 {
            font-family: "Segoe UI", sans-serif;
            color: #333;
        }
        h1 {
            font-size: 28px;
            margin-bottom: 20px;
        }
        h2 {
            font-size: 20px;
            margin-bottom: 15px;
        }
        .test-options {
            display: flex;
            flex-direction: column;
            gap: 10px;
            margin: 20px auto;
            width: 300px;
        }
        .test-options select, .test-options button {
            padding: 10px;
            font-size: 16px;
            border: 1px solid #fff;
            border-radius: 5px;
            outline: none;
            transition: transform 0.2s, box-shadow 0.2s;
        }
        .test-options select:hover, .test-options button:hover {
            transform: scale(1.05);
            box-shadow: 0 4px 8px rgba(0, 0, 0, 0.2);
        }
        .test-options button {
            background-color: #007bff;
            color: white;
            border: none;
            cursor: pointer;
        }
        .test-options button:hover {
            background-color: #0056b3;
        }
        .footer-options {
            display: flex;
            justify-content: space-around;
            margin-top: 40px;
        }
        .footer-options div {
            text-align: center;
        }
        .footer-options div a {
            display: inline-block;
            margin-top: 10px;
            padding: 10px 20px;
            font-size: 16px;
            color: #fff;
            text-decoration: none;
            border: 1px solid #007bff;
            border-radius: 20px;
            transition: background-color 0.3s, color 0.3s, transform 0.2s;
        }
        .footer-options div a:hover {
            background-color: #007bff;
            color: white;
            transform: scale(1.1);
        }

        /* Chart and Leaderboard */
        .extra-sections {
            display: flex;
            justify-content: space-around;
            margin-top: 40px;
        }
        .chart-container  {
            width: 45%;
            background: white;
            padding: 15px;
            border-radius: 10px;
            box-shadow: 0 4px 8px rgba(0, 0, 0, 0.1);
            position: relative;
            overflow: hidden;
        }

        /* New Styling for Blur and Lock */
        .content-wrapper {
            position: relative;
        }
        .blurred {
            filter: blur(8px);
            pointer-events: none;
        }
        .locked-content {
            position: absolute;
            top: 50%;
            left: 50%;
            transform: translate(-50%, -50%);
            text-align: center;
            background: rgba(255, 255, 255, 0.8);
            padding: 10px 20px;
            border-radius: 8px;
            z-index: 1;
        }
        .locked-content span {
            font-size: 24px;
            margin-bottom: 10px;
        }
        .locked-content p {
            font-size: 18px;
            color: #555;
        }
        .locked-content a {
            color: #007bff;
            font-weight: bold;
            text-decoration: none;
        }
        .locked-content a:hover {
            text-decoration: underline;
        }

        .leaderboard-container h3 {
            background-color: #4CAF50;
            color: white;
            text-align: center;
            padding: 10px;
            margin: 0;
            font-size: 2em;
            font-weight: bold;
            border-radius: 8px 8px 0 0;
        }
        .leaderboard-container table {
            width: 100%;
            border-collapse: collapse;
            margin: 20px auto;
            font-size: 1.2em;
        }
        .leaderboard-container th, .leaderboard-container td {
            padding: 10px 8px;
            text-align: center;
        }
        .leaderboard-container th {
            background-color: #e0e0e0;
            font-size: 1.4em;
            text-transform: uppercase;
        }
        .leaderboard-container tr:nth-child(odd) {
            background-color: #f5f5f5;
        }
        .leaderboard-container tr:nth-child(even) {
            background-color: #ffffff;
        }
        .leaderboard-container tr:hover {
            background-color: #e8f5e9;
            transition: background-color 0.3s ease;
        }
        .leaderboard-container table {
            border: 2px solid #ddd;
            border-radius: 8px;
            overflow: hidden;
        }
    </style>
</head>
<body>
    <header>
        <div class="header-left">TypeFaster</div>
        <div class="nav-middle">
            <a href="quiz.html">Typing Quiz</a>
            <a href="Typing-tutor.html">Typing Tutorial</a>
            <a href="aboutus.html">About us</a>
            <a href="subscribe.html">Subscribe</a>
            <a href="contact.html">Contact Us</a>
        </div>
        <div class="user-box">
            <a href="profile.php">PROFILE</a>
        </div>
    </header>
    
    <main>
        <h1>CHECK YOUR TYPING SPEED IN A MINUTE</h1>
        <h2>SELECT TEST</h2>
        <div class="test-options">
           <select id="timeSelect">
                <option value="1">1 Minute</option>
                <option value="2">2 Minutes</option>
                <option value="5">5 Minutes</option>
            </select>
            <select id="difficultySelect">
                <option value="easy">Easy</option>
                <option value="medium">Medium</option>
                <option value="hard">Hard</option>
            </select>
            <button onclick="startTest()">START TEST</button>
        </div>

        <div class="extra-sections">
            <?php if ($isSubscribed): ?>
                <!-- Unlocked Chart Section -->
                <div class="chart-container">
                    <div class="content-wrapper">
                        <h3>Typing Speed Progress (WPM)</h3>
                        <canvas id="wpmChart"></canvas>
                    </div>
                </div>

                <!-- Unlocked Leaderboard Section -->
                <div class="leaderboard-container">
                    <div class="content-wrapper">
                        <h3>Leaderboard</h3>
                        <table>
                            <thead>
                                <tr>
                                    <th>Rank</th>
                                    <th>Username</th>
                                    <th>Highest WPM</th>
                                </tr>
                            </thead>
                            <tbody id="leaderboardBody">
                                <!-- Leaderboard data will be dynamically inserted here -->
                            </tbody>
                        </table>
                    </div>
                </div>
            <?php else: ?>
                <!-- Locked Chart Section -->
                <div class="chart-container">
                    <div class="content-wrapper">
                        <div class="blurred">
                            <h3>Typing Speed Progress (WPM)</h3>
                            <canvas id="wpmChart"></canvas>
                        </div>
                        <div class="locked-content">
                            <span>🔒</span>
                            <p>Subscribe to view your progress!</p>
                            <a href="subscribe.html">Subscribe Now</a>
                        </div>
                    </div>
                </div>

                <!-- Locked Leaderboard Section -->
                <div class="leaderboard-container">
                    <div class="content-wrapper">
                        <div class="blurred">
                            <h3>Leaderboard</h3>
                            <table>
                                <tr>
                                    <th>Rank</th>
                                    <th>Username</th>
                                    <th>Score</th>
                                </tr>
                            </table>
                        </div>
                        <div class="locked-content">
                            <span>🔒</span>
                            <p>Subscribe to access the leaderboard!</p>
                            <a href="subscribe.html">Subscribe Now</a>
                        </div>
                    </div>
                </div>
            <?php endif; ?>
        </div>
        </div>
    </main>

    <script>
        // If the user is subscribed, fetch and update the chart and leaderboard data
        <?php if ($isSubscribed): ?>
            // Chart.js Typing Speed Progress Chart
            const ctx = document.getElementById('wpmChart').getContext('2d');
            const wpmChart = new Chart(ctx, {
                type: 'line',
                data: {
                    labels: [], // Initially empty; will be updated via fetch
                    datasets: [{
                        label: 'Typing Speed (WPM)',
                        data: [], // Initially empty; will be updated via fetch
                        borderColor: 'blue',
                        backgroundColor: 'rgba(0, 123, 255, 0.1)',
                        borderWidth: 2,
                        pointBackgroundColor: 'blue',
                        pointBorderColor: 'white',
                        pointHoverRadius: 8,
                        tension: 0.3
                    }]
                },
                options: {
                    responsive: true,
                    plugins: {
                        legend: {
                            display: true,
                            position: 'top',
                            labels: {
                                color: '#333',
                                font: {
                                    size: 14
                                }
                            }
                        },
                        tooltip: {
                            callbacks: {
                                label: function(context) {
                                    return `WPM: ${context.raw}`;
                                }
                            }
                        }
                    },
                    scales: {
                        x: {
                            title: {
                                display: true,
                                text: 'Test Date',
                                color: '#555',
                                font: {
                                    size: 14
                                }
                            },
                            grid: {
                                color: 'rgba(200, 200, 200, 0.3)'
                            }
                        },
                        y: {
                            title: {
                                display: true,
                                text: 'Words Per Minute (WPM)',
                                color: '#555',
                                font: {
                                    size: 14
                                }
                            },
                            grid: {
                                color: 'rgba(200, 200, 200, 0.3)'
                            }
                        }
                    }
                }
            });

            // Function to fetch and update chart data
            function fetchAndUpdateChartData() {
                fetch('fetch_chart_data.php')
                    .then(response => response.json())
                    .then(data => {
                        if (data.error) {
                            console.error(data.error);
                            return;
                        }
                        const labels = data.map(item => item.test_date);
                        const wpmData = data.map(item => item.wpm);
                        wpmChart.data.labels = labels;
                        wpmChart.data.datasets[0].data = wpmData;
                        wpmChart.update();
                    })
                    .catch(error => console.error('Error fetching chart data:', error));
            }

            // Call the function to load chart data on page load
            fetchAndUpdateChartData();

            // Fetch and display leaderboard data
            fetch('fetch_leaderboard.php')
                .then(response => response.json())
                .then(data => {
                    const leaderboardBody = document.getElementById('leaderboardBody');
                    leaderboardBody.innerHTML = '';
                    data.forEach((user, index) => {
                        const row = document.createElement('tr');
                        const rankCell = document.createElement('td');
                        rankCell.textContent = index + 1;
                        rankCell.style.padding = '10px';
                        rankCell.style.borderBottom = '2px solid #ddd';

                        const usernameCell = document.createElement('td');
                        usernameCell.textContent = user.username;
                        usernameCell.style.padding = '10px';
                        usernameCell.style.borderBottom = '2px solid #ddd';

                        const wpmCell = document.createElement('td');
                        wpmCell.textContent = user.highest_wpm;
                        wpmCell.style.padding = '10px';
                        wpmCell.style.borderBottom = '2px solid #ddd';

                        row.appendChild(rankCell);
                        row.appendChild(usernameCell);
                        row.appendChild(wpmCell);
                        leaderboardBody.appendChild(row);
                    });
                })
                .catch(error => console.error('Error fetching leaderboard data:', error));
        <?php endif; ?>

        // Start Test Functionality
        function startTest() {
            const time = document.getElementById("timeSelect").value;
            const difficulty = document.getElementById("difficultySelect").value;
            window.location.href = `http://localhost/typing-website/test.html?time=${time}&difficulty=${difficulty}`;
        }
    </script>
</body>
</html>
