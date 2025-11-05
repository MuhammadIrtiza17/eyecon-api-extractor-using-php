<?php
$servername = "localhost";
$username = "root";
$password = "";
$dbname = "eyecon";
$conn = new mysqli($servername, $username, $password, $dbname);
if ($conn->connect_error) {
    die("DB Connection failed: " . $conn->connect_error);
}

$conn->query("ALTER TABLE eyecon_results ADD COLUMN IF NOT EXISTS country_code VARCHAR(10) DEFAULT '92' AFTER id");

$message = '';
$message_type = '';

if (isset($_GET['clear_all'])) {
    $conn->query("TRUNCATE TABLE eyecon_results");
    $message = "All records cleared successfully!";
    $message_type = "success";
}

if (isset($_GET['download'])) {
    header('Content-Type: text/csv');
    header('Content-Disposition: attachment; filename="eyecon_results.csv"');
    $output = fopen("php://output", "w");
    fputcsv($output, ['ID', 'Country Code', 'Phone Number', 'Count', 'Name1', 'Name2', 'Name3', 'Name4', 'Facebook Link', 'Profile Image', 'Created At']);
    $rows = $conn->query("SELECT * FROM eyecon_results ORDER BY id DESC");
    while ($row = $rows->fetch_assoc()) {
        fputcsv($output, $row);
    }
    fclose($output);
    exit;
}

// Single number processing endpoint
if (isset($_POST['process_single'])) {
    header('Content-Type: application/json');
    
    $country_code = $_POST['country_code'];
    $phone = $_POST['phone'];
    
    $result = processSingleNumber($country_code, $phone, $conn);
    
    echo json_encode($result);
    exit;
}

$api_remaining = 'Unknown';
$api_used = 'Unknown';
$api_limit = 'Unknown';

function cleanFacebookUrl($url) {
    if (empty($url) || $url == 'NOT FOUND') {
        return 'NOT FOUND';
    }
    
    // Remove unwanted characters
    $url = str_replace(['\\', '"', '\''], '', $url);
    $url = trim($url);
    
    // Fix common URL issues
    $url = str_replace(
        ['https: Www.facebook.com', 'https: \\/\\/www.facebook.com', 'http: \\/\\/www.facebook.com'],
        'https://www.facebook.com',
        $url
    );
    
    // Ensure proper protocol
    if (strpos($url, 'http') !== 0) {
        $url = 'https://' . ltrim($url, '/');
    }
    
    // Handle graph.facebook.com URLs (convert to profile URL)
    if (strpos($url, 'graph.facebook.com') !== false) {
        preg_match('/(\d+)/', $url, $matches);
        if (isset($matches[1])) {
            $url = 'https://facebook.com/' . $matches[1];
        } else {
            return 'NOT FOUND';
        }
    }
    
    // Validate it's a proper Facebook URL
    if (strpos($url, 'facebook.com') === false) {
        return 'NOT FOUND';
    }
    
    return $url;
}

function processSingleNumber($country_code, $phone, $conn) {
    $curl = curl_init();
    curl_setopt_array($curl, [
        CURLOPT_URL => "https://eyecon.p.rapidapi.com/api/v1/search?code=$country_code&number=$phone",
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_ENCODING => "",
        CURLOPT_MAXREDIRS => 10,
        CURLOPT_TIMEOUT => 30,
        CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
        CURLOPT_CUSTOMREQUEST => "GET",
        CURLOPT_HTTPHEADER => [
            "x-rapidapi-host: eyecon.p.rapidapi.com",
            "x-rapidapi-key: af06ef6db4msh8c22240be2a2f8cp1c7745jsne9e42b07dc54"
        ],
    ]);

    $response = curl_exec($curl);
    $err = curl_error($curl);
    $http_code = curl_getinfo($curl, CURLINFO_HTTP_CODE);
    curl_close($curl);

    if ($err) {
        return ['success' => false, 'error' => $err];
    }

    $data = json_decode($response, true);
    
    if (!isset($data['data'])) {
        return ['success' => false, 'error' => 'No data found in response'];
    }
    
    // Extract data from JSON response
    $fullName = $data['data']['fullName'] ?? 'NOT FOUND';
    $otherNames = $data['data']['otherNames'] ?? [];
    $profileImage = $data['data']['image'] ?? 'NOT FOUND';
    
    $names = [];
    if (!empty($fullName) && $fullName != 'NOT FOUND') {
        $names[] = $fullName;
    }
    
    foreach ($otherNames as $otherName) {
        if (isset($otherName['name'])) {
            $names[] = $otherName['name'];
        }
    }
    
    $name1 = $names[0] ?? 'NOT FOUND';
    $name2 = $names[1] ?? 'N/A';
    $name3 = $names[2] ?? 'N/A';
    $name4 = $names[3] ?? 'N/A';
    
    // Extract Facebook link
    $facebook_link = 'NOT FOUND';

    if (isset($data['data']['facebookID'])) {
        $fb_data = $data['data']['facebookID'];
        
        // Handle both array and object formats
        if (is_array($fb_data) && isset($fb_data[0])) {
            $fb_data = $fb_data[0];
        }
        
        // Priority 1: Use profileURL if available
        if (isset($fb_data['profileURL']) && !empty($fb_data['profileURL'])) {
            $facebook_link = cleanFacebookUrl($fb_data['profileURL']);
        }
        // Priority 2: Use url if available
        elseif (isset($fb_data['url']) && !empty($fb_data['url'])) {
            $facebook_link = cleanFacebookUrl($fb_data['url']);
        }
        // Priority 3: Construct URL from ID
        elseif (isset($fb_data['id']) && !empty($fb_data['id'])) {
            $facebook_link = 'https://facebook.com/' . $fb_data['id'];
        }
    }

    // Also check if there's a direct Facebook link in the images array
    if ($facebook_link == 'NOT FOUND' && isset($data['data']['images'])) {
        foreach ($data['data']['images'] as $image) {
            if (isset($image['id']) && is_numeric($image['id'])) {
                $facebook_link = 'https://facebook.com/' . $image['id'];
                break;
            }
        }
    }
    
    // Extract profile image
    $profile_image_url = 'NOT FOUND';
    if (!empty($profileImage) && $profileImage != 'NOT FOUND') {
        $profile_image_url = $profileImage;
    } elseif (isset($data['data']['images'][0]['pictures']['600'])) {
        $profile_image_url = $data['data']['images'][0]['pictures']['600'];
    } elseif (isset($data['data']['images'][0]['pictures']['200'])) {
        $profile_image_url = $data['data']['images'][0]['pictures']['200'];
    }
    
    $count_value = count($names);
    
    // Save to database
    $stmt = $conn->prepare("INSERT INTO eyecon_results (country_code, phone_number, count_value, name1, name2, name3, name4, facebook_link, profile_image) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)");
    $stmt->bind_param("ssissssss", $country_code, $phone, $count_value, $name1, $name2, $name3, $name4, $facebook_link, $profile_image_url);
    $stmt->execute();
    $insert_id = $conn->insert_id;
    $stmt->close();
    
    return [
        'success' => true,
        'id' => $insert_id,
        'country_code' => $country_code,
        'phone' => $phone,
        'count_value' => $count_value,
        'name1' => $name1,
        'name2' => $name2,
        'name3' => $name3,
        'name4' => $name4,
        'facebook_link' => $facebook_link,
        'profile_image' => $profile_image_url,
        'created_at' => date('Y-m-d H:i:s')
    ];
}
?>
<!DOCTYPE html>
<html>
<head>
    <style>
        body { 
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif; 
            margin: 40px; 
            background: linear-gradient(135deg, #ecececff 0%, #ffe7dfff 100%);
            color: #2c3e50;
        }
        .container {
            max-width: 1200px;
            margin: 0 auto;
        }
        form { 
            background: rgba(255, 255, 255, 0.95); 
            padding: 30px; 
            border-radius: 15px; 
            box-shadow: 0 10px 30px rgba(0,0,0,0.1);
            backdrop-filter: blur(10px);
            margin-bottom: 25px;
        }
        textarea { 
            width: 100%; 
            height: 300px; 
            padding: 15px; 
            margin: 15px 0; 
            border: 1px solid #bdc3c7; 
            border-radius: 8px; 
            font-family: monospace; 
            background: #f8f9fa;
            transition: all 0.3s ease;
            font-size: 14px;
        }
        textarea:focus {
            border-color: #3498db;
            box-shadow: 0 0 0 3px rgba(52, 152, 219, 0.1);
            outline: none;
        }
        button { 
            background: linear-gradient(135deg, #3498db, #2980b9); 
            color: #fff; 
            border: none; 
            padding: 14px 28px; 
            border-radius: 8px; 
            cursor: pointer; 
            margin-right: 12px; 
            font-weight: 600;
            transition: all 0.3s ease;
            box-shadow: 0 4px 15px rgba(52, 152, 219, 0.3);
        }
        button:hover { 
            transform: translateY(-2px);
            box-shadow: 0 6px 20px rgba(52, 152, 219, 0.4);
        }
        button:disabled {
            background: #bdc3c7;
            cursor: not-allowed;
            transform: none;
            box-shadow: none;
        }
        .instructions { 
            background: rgba(52, 152, 219, 0.1); 
            padding: 20px; 
            border-radius: 10px; 
            margin-bottom: 25px; 
            border-left: 4px solid #3498db;
        }
        table { 
            width: 100%; 
            border-collapse: collapse; 
            margin-top: 25px; 
            background: rgba(255, 255, 255, 0.95);
            border-radius: 10px;
            overflow: hidden;
            box-shadow: 0 10px 30px rgba(0,0,0,0.1);
        }
        th, td { 
            border: 1px solid #ecf0f1; 
            padding: 14px; 
            text-align: left; 
            vertical-align: top;
        }
        th { 
            background: linear-gradient(135deg, #34495e, #2c3e50); 
            color: white; 
            font-weight: 600;
        }
        tr:nth-child(even) { 
            background-color: rgba(236, 240, 241, 0.5); 
        }
        tr:hover {
            background-color: rgba(52, 152, 219, 0.1);
            transition: all 0.3s ease;
        }
        .actions { 
            margin: 25px 0; 
        }
        .actions a { 
            background: linear-gradient(135deg, #2ecc71, #27ae60); 
            color: white; 
            padding: 12px 20px; 
            border-radius: 8px; 
            text-decoration: none; 
            margin-right: 12px; 
            display: inline-block; 
            font-weight: 600;
            transition: all 0.3s ease;
            box-shadow: 0 4px 15px rgba(46, 204, 113, 0.3);
        }
        .actions a:hover { 
            transform: translateY(-2px);
            box-shadow: 0 6px 20px rgba(46, 204, 113, 0.4);
        }
        .clear-btn { 
            background: linear-gradient(135deg, #e74c3c, #c0392b) !important; 
        }
        .clear-btn:hover { 
            background: linear-gradient(135deg, #c0392b, #a93226) !important; 
        }
        .facebook-link { 
            color: #3498db; 
            text-decoration: none; 
            font-weight: 600;
        }
        .facebook-link:hover { 
            text-decoration: underline; 
            color: #2980b9;
        }
        .profile-image {
            width: 50px;
            height: 50px;
            border-radius: 50%;
            object-fit: cover;
            border: 2px solid #3498db;
        }
        .count-badge {
            display: inline-block;
            padding: 4px 8px;
            background: #3498db;
            color: white;
            border-radius: 12px;
            font-size: 12px;
            font-weight: bold;
        }
        .entry-count {
            background: rgba(52, 152, 219, 0.1);
            padding: 10px 15px;
            border-radius: 8px;
            margin: 10px 0;
            font-weight: 600;
            color: #2c3e50;
        }
        .progress-container {
            margin: 20px 0;
            padding: 15px;
            background: rgba(52, 152, 219, 0.1);
            border-radius: 10px;
            display: none;
        }
        .progress-bar {
            width: 100%;
            height: 20px;
            background: #ecf0f1;
            border-radius: 10px;
            overflow: hidden;
            margin: 10px 0;
        }
        .progress-fill {
            height: 100%;
            background: linear-gradient(90deg, #2ecc71, #27ae60);
            width: 0%;
            transition: width 0.3s ease;
        }
        .progress-text {
            text-align: center;
            font-weight: 600;
            color: #2c3e50;
        }
        .live-results {
            margin: 20px 0;
            max-height: 400px;
            overflow-y: auto;
            border: 1px solid #bdc3c7;
            border-radius: 10px;
            padding: 15px;
            background: white;
        }
        .result-item {
            padding: 10px;
            margin: 5px 0;
            border-radius: 5px;
            background: #f8f9fa;
            border-left: 4px solid #3498db;
        }
        .result-item.success {
            border-left-color: #27ae60;
        }
        .result-item.error {
            border-left-color: #e74c3c;
        }

        /* Popup Styles */
        .popup-overlay {
            position: fixed;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background: rgba(0, 0, 0, 0.5);
            display: flex;
            justify-content: center;
            align-items: center;
            z-index: 1000;
            backdrop-filter: blur(5px);
        }

        .popup {
            background: white;
            padding: 30px;
            border-radius: 15px;
            box-shadow: 0 20px 40px rgba(0,0,0,0.3);
            max-width: 500px;
            width: 90%;
            text-align: center;
            animation: popupAppear 0.3s ease-out;
        }

        @keyframes popupAppear {
            from {
                opacity: 0;
                transform: scale(0.8) translateY(-20px);
            }
            to {
                opacity: 1;
                transform: scale(1) translateY(0);
            }
        }

        .popup.success {
            border-top: 5px solid #27ae60;
        }

        .popup.warning {
            border-top: 5px solid #f39c12;
        }

        .popup.info {
            border-top: 5px solid #3498db;
        }

        .popup-icon {
            font-size: 48px;
            margin-bottom: 15px;
        }

        .popup.success .popup-icon {
            color: #27ae60;
        }

        .popup.warning .popup-icon {
            color: #f39c12;
        }

        .popup.info .popup-icon {
            color: #3498db;
        }

        .popup h3 {
            margin: 0 0 15px 0;
            color: #2c3e50;
        }

        .popup p {
            margin: 0 0 25px 0;
            color: #7f8c8d;
            line-height: 1.5;
        }

        .popup-buttons {
            display: flex;
            gap: 10px;
            justify-content: center;
        }

        .popup-btn {
            padding: 10px 20px;
            border: none;
            border-radius: 8px;
            cursor: pointer;
            font-weight: 600;
            transition: all 0.3s ease;
        }

        .popup-btn.confirm {
            background: linear-gradient(135deg, #27ae60, #229954);
            color: white;
        }

        .popup-btn.cancel {
            background: linear-gradient(135deg, #95a5a6, #7f8c8d);
            color: white;
        }

        .popup-btn:hover {
            transform: translateY(-2px);
            box-shadow: 0 4px 15px rgba(0,0,0,0.2);
        }

        .hidden {
            display: none;
        }
    </style>
</head>
<body>

<div class="container">
    <div id="popupContainer"></div>

    <div id="confirmationPopup" class="popup-overlay hidden">
        <div class="popup info">
            <h3>Confirm Action</h3>
            <p id="popupMessage">Are you sure you want to perform this action?</p>
            <div class="popup-buttons">
                <button id="confirmAction" class="popup-btn confirm">Yes, Continue</button>
                <button id="cancelAction" class="popup-btn cancel">Cancel</button>
            </div>
        </div>
    </div>

    <div id="messagePopup" class="popup-overlay hidden">
        <div class="popup" id="messagePopupType">
            <div class="popup-icon" id="messageIcon">✓</div>
            <h3 id="messageTitle">Success</h3>
            <p id="messageText">Operation completed successfully!</p>
            <div class="popup-buttons">
                <button id="closeMessage" class="popup-btn confirm">OK</button>
            </div>
        </div>
    </div>

    <form method="POST" id="phoneForm">
        <textarea name="phone_numbers" placeholder="Enter numbers (one per line)&#10;Examples:&#10;92,3003039859&#10;91,9768836827&#10;3123456789&#10;1,5551234567" required></textarea>
        <div class="entry-count" id="entryCount">Entries: 0/600</div>
        
        <div class="progress-container" id="progressContainer">
            <div class="progress-bar">
                <div class="progress-fill" id="progressFill"></div>
            </div>
            <div class="progress-text" id="progressText">Processing: 0/0</div>
        </div>
        
        <div class="live-results" id="liveResults" style="display: none;">
            <h4>Live Results:</h4>
            <div id="resultsContainer"></div>
        </div>
        
        <br>
        <button type="submit" id="fetchButton">Fetch Data & Save to DB</button>
        <button type="button" id="stopButton" style="display: none; background: #e74c3c;">Stop Processing</button>
    </form>

    <div class="actions">
        <a href="#" id="downloadLink">Download CSV</a>
        <a href="#" id="clearAllLink" class="clear-btn">Clear All Records</a>
    </div>

    <?php
    $saved_results = $conn->query("SELECT * FROM eyecon_results ORDER BY id DESC");

    if ($saved_results->num_rows > 0) {
        echo "<div style='margin-top: 40px;'>";
        echo "<h3>All Results in Database</h3>";
        echo "<table>";
        echo "<thead>
                <tr>
                    <th>ID</th>
                    <th>Country</th>
                    <th>Phone Number</th>
                    <th>Count</th>
                    <th>Name1</th>
                    <th>Name2</th>
                    <th>Name3</th>
                    <th>Name4</th>
                    <th>Facebook Link</th>
                    <th>Profile Image</th>
                    <th>Created At</th>
                </tr>
              </thead>";
        echo "<tbody>";
        while ($row = $saved_results->fetch_assoc()) {
            echo "<tr>
                    <td>{$row['id']}</td>
                    <td>+{$row['country_code']}</td>
                    <td>{$row['phone_number']}</td>
                    <td><span class='count-badge'>{$row['count_value']}</span></td>
                    <td>{$row['name1']}</td>
                    <td>{$row['name2']}</td>
                    <td>{$row['name3']}</td>
                    <td>{$row['name4']}</td>
                    <td>";
            if ($row['facebook_link'] != 'NOT FOUND' && $row['facebook_link'] != 'N/A' && filter_var($row['facebook_link'], FILTER_VALIDATE_URL)) {
                echo "<a href='{$row['facebook_link']}' class='facebook-link' target='_blank' rel='noopener noreferrer'>View Facebook</a>";
            } else {
                echo 'NOT FOUND';
            }
            echo "</td>
                    <td>";
            if ($row['profile_image'] != 'NOT FOUND' && $row['profile_image'] != 'N/A') {
                echo "<img src='{$row['profile_image']}' class='profile-image' alt='Profile Image' onerror=\"this.style.display='none'\">";
            } else {
                echo $row['profile_image'];
            }
            echo "</td>
                    <td>{$row['created_at']}</td>
                  </tr>";
        }
        echo "</tbody>";
        echo "</table>";
        echo "</div>";
    } else {
        echo "<p style='margin-top: 20px; color:#34495e;'>.</p>";
    }

    $conn->close();
    ?>
</div>

<script>
    <?php if (!empty($message)): ?>
    document.addEventListener('DOMContentLoaded', function() {
        showMessage('<?php echo $message; ?>', '<?php echo $message_type; ?>');
    });
    <?php endif; ?>
    
    let isProcessing = false;
    let currentQueue = [];
    let processedCount = 0;
    let totalCount = 0;
    
    // Entry counter
    document.querySelector('textarea[name="phone_numbers"]').addEventListener('input', function() {
        const lines = this.value.split('\n').filter(line => line.trim() !== '');
        const count = Math.min(lines.length, 600);
        document.getElementById('entryCount').textContent = `Entries: ${count}/600`;
        
        if (count >= 600) {
            this.value = lines.slice(0, 600).join('\n');
        }
    });
    
    function showConfirmation(message, callback) {
        const popup = document.getElementById('confirmationPopup');
        const messageElement = document.getElementById('popupMessage');
        const confirmBtn = document.getElementById('confirmAction');
        const cancelBtn = document.getElementById('cancelAction');
        
        messageElement.textContent = message;
        popup.classList.remove('hidden');
        
        const cleanUp = () => {
            popup.classList.add('hidden');
            confirmBtn.onclick = null;
            cancelBtn.onclick = null;
        };
        
        confirmBtn.onclick = () => {
            cleanUp();
            callback(true);
        };
        
        cancelBtn.onclick = () => {
            cleanUp();
            callback(false);
        };
    }
    
    function showMessage(message, type = 'success') {
        const popup = document.getElementById('messagePopup');
        const messageElement = document.getElementById('messageText');
        const titleElement = document.getElementById('messageTitle');
        const iconElement = document.getElementById('messageIcon');
        const typeElement = document.getElementById('messagePopupType');
        const closeBtn = document.getElementById('closeMessage');
        
        messageElement.textContent = message;
        typeElement.className = 'popup ' + type;
        
        switch(type) {
            case 'success':
                titleElement.textContent = 'Success';
                break;
            case 'warning':
                titleElement.textContent = 'Warning';
                break;
            case 'info':
                titleElement.textContent = 'Information';
                break;
        }
        
        popup.classList.remove('hidden');
        
        closeBtn.onclick = () => {
            popup.classList.add('hidden');
        };
    }
    
    function updateProgress() {
        const progressFill = document.getElementById('progressFill');
        const progressText = document.getElementById('progressText');
        const percentage = totalCount > 0 ? (processedCount / totalCount) * 100 : 0;
        
        progressFill.style.width = percentage + '%';
        progressText.textContent = `Processing: ${processedCount}/${totalCount}`;
    }
    
    function addResultToTable(result) {
        const tableBody = document.querySelector('table tbody');
        const newRow = document.createElement('tr');
        
        const facebookLink = result.facebook_link !== 'NOT FOUND' && result.facebook_link !== 'N/A' && 
                            result.facebook_link.startsWith('http') ? 
            `<a href="${result.facebook_link}" class="facebook-link" target="_blank" rel="noopener noreferrer">View Facebook</a>` : 
            'NOT FOUND';
            
        const profileImage = result.profile_image !== 'NOT FOUND' && result.profile_image !== 'N/A' ? 
            `<img src="${result.profile_image}" class="profile-image" alt="Profile Image" onerror="this.style.display='none'">` : 
            result.profile_image;
        
        newRow.innerHTML = `
            <td>${result.id}</td>
            <td>+${result.country_code}</td>
            <td>${result.phone}</td>
            <td><span class="count-badge">${result.count_value}</span></td>
            <td>${result.name1}</td>
            <td>${result.name2}</td>
            <td>${result.name3}</td>
            <td>${result.name4}</td>
            <td>${facebookLink}</td>
            <td>${profileImage}</td>
            <td>${result.created_at}</td>
        `;
        
        if (tableBody) {
            tableBody.insertBefore(newRow, tableBody.firstChild);
        }
    }
    
    function processNextNumber() {
        if (!isProcessing || currentQueue.length === 0) {
            // All numbers processed
            isProcessing = false;
            document.getElementById('fetchButton').disabled = false;
            document.getElementById('stopButton').style.display = 'none';
            document.getElementById('progressContainer').style.display = 'none';
            
            showMessage(`Processing completed! ${processedCount} numbers processed successfully.`, 'success');
            return;
        }
        
        const nextNumber = currentQueue.shift();
        const [country_code, phone] = nextNumber;
        
        // Update live results
        const resultsContainer = document.getElementById('resultsContainer');
        const currentItem = document.createElement('div');
        currentItem.className = 'result-item';
        currentItem.innerHTML = `Processing: +${country_code} ${phone}...`;
        resultsContainer.appendChild(currentItem);
        resultsContainer.scrollTop = resultsContainer.scrollHeight;
        
        // Send AJAX request
        fetch('', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/x-www-form-urlencoded',
            },
            body: `process_single=true&country_code=${encodeURIComponent(country_code)}&phone=${encodeURIComponent(phone)}`
        })
        .then(response => response.json())
        .then(data => {
            processedCount++;
            updateProgress();
            
            if (data.success) {
                currentItem.className = 'result-item success';
                currentItem.innerHTML = `✓ +${country_code} ${phone} → ${data.name1}`;
                addResultToTable(data);
            } else {
                currentItem.className = 'result-item error';
                currentItem.innerHTML = `✗ +${country_code} ${phone} → Error: ${data.error}`;
            }
            
            // Process next number after a short delay
            setTimeout(processNextNumber, 500);
        })
        .catch(error => {
            processedCount++;
            updateProgress();
            
            currentItem.className = 'result-item error';
            currentItem.innerHTML = `✗ +${country_code} ${phone} → Network error`;
            
            // Continue with next number
            setTimeout(processNextNumber, 500);
        });
    }
    
    document.getElementById('clearAllLink').addEventListener('click', function(e) {
        e.preventDefault();
        showConfirmation('Are you sure you want to clear ALL records from the database? This action cannot be undone.', function(confirmed) {
            if (confirmed) {
                window.location.href = '?clear_all=true';
            }
        });
    });
    
    document.getElementById('downloadLink').addEventListener('click', function(e) {
        e.preventDefault();
        showConfirmation('Do you want to download the results as CSV file?', function(confirmed) {
            if (confirmed) {
                window.location.href = '?download=true';
            }
        });
    });
    
    document.getElementById('stopButton').addEventListener('click', function() {
        isProcessing = false;
        this.style.display = 'none';
        document.getElementById('fetchButton').disabled = false;
        showMessage('Processing stopped.', 'warning');
    });
    
    document.getElementById('phoneForm').addEventListener('submit', function(e) {
        e.preventDefault();
        
        const textarea = this.querySelector('textarea');
        const numbers = textarea.value.trim().split('\n').filter(line => line.trim() !== '');
        
        if (numbers.length === 0) {
            showMessage('Please enter at least one phone number to process.', 'warning');
            return;
        }
        
        if (numbers.length > 600) {
            showMessage('Maximum 600 entries allowed. Please reduce the number of entries.', 'warning');
            return;
        }
        
        showConfirmation(`You are about to process ${numbers.length} phone number(s). This will use your API credits. Continue?`, function(confirmed) {
            if (confirmed) {
                // Prepare the queue
                currentQueue = [];
                numbers.forEach(line => {
                    const parts = line.split(/[\t,]/);
                    if (parts.length >= 2) {
                        const country_code = parts[0].replace(/[^0-9]/g, '');
                        const phone = parts[1].replace(/[^0-9]/g, '');
                        if (phone) {
                            currentQueue.push([country_code || '92', phone]);
                        }
                    } else {
                        const phone = parts[0].replace(/[^0-9]/g, '');
                        if (phone) {
                            currentQueue.push(['92', phone]);
                        }
                    }
                });
                
                // Reset counters
                processedCount = 0;
                totalCount = currentQueue.length;
                
                // Show progress
                document.getElementById('progressContainer').style.display = 'block';
                document.getElementById('liveResults').style.display = 'block';
                document.getElementById('resultsContainer').innerHTML = '';
                document.getElementById('fetchButton').disabled = true;
                document.getElementById('stopButton').style.display = 'inline-block';
                
                updateProgress();
                
                // Start processing
                isProcessing = true;
                processNextNumber();
            }
        });
    });
</script>

</body>
</html>