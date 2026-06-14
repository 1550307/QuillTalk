<!DOCTYPE html>
<html>
<head>
    <title>AI Chat System Test</title>
</head>
<body>
    <h1>AI Chat System Test</h1>
    
    <h2>1. Test Database Tables</h2>
    <button onclick="testTables()">Test Tables</button>
    <div id="tableResult"></div>
    
    <h2>2. Test AI Chat Creation</h2>
    <button onclick="testCreateAiChat()">Create Test AI Chat</button>
    <div id="createResult"></div>
    
    <h2>3. Test Fetch AI Chats</h2>
    <button onclick="testFetchAiChats()">Fetch AI Chats</button>
    <div id="fetchResult"></div>
    
    <h2>4. Test Send AI Message</h2>
    <input type="text" id="testMessage" placeholder="Test message" value="Hello AI!">
    <button onclick="testSendMessage()">Send Message</button>
    <div id="messageResult"></div>

    <script>
        // Get token from URL or use a test token
        const urlParams = new URLSearchParams(window.location.search);
        const token = urlParams.get('token') || 'test_token';
        
        async function testTables() {
            try {
                const response = await fetch('test_ai_tables.php');
                const text = await response.text();
                document.getElementById('tableResult').innerHTML = '<pre>' + text + '</pre>';
            } catch (e) {
                document.getElementById('tableResult').innerHTML = 'Error: ' + e.message;
            }
        }
        
        async function testCreateAiChat() {
            try {
                const formData = new FormData();
                formData.append('token', token);
                formData.append('display_name', 'Test AI');
                formData.append('bio', 'This is a test AI chat');
                formData.append('notes', 'Test notes');
                
                const response = await fetch('create_ai_chat.php', {
                    method: 'POST',
                    body: formData
                });
                
                const data = await response.json();
                document.getElementById('createResult').innerHTML = '<pre>' + JSON.stringify(data, null, 2) + '</pre>';
            } catch (e) {
                document.getElementById('createResult').innerHTML = 'Error: ' + e.message;
            }
        }
        
        async function testFetchAiChats() {
            try {
                const response = await fetch(`fetch_ai_chats.php?token=${encodeURIComponent(token)}`);
                const data = await response.json();
                document.getElementById('fetchResult').innerHTML = '<pre>' + JSON.stringify(data, null, 2) + '</pre>';
            } catch (e) {
                document.getElementById('fetchResult').innerHTML = 'Error: ' + e.message;
            }
        }
        
        async function testSendMessage() {
            const message = document.getElementById('testMessage').value;
            if (!message) {
                alert('Please enter a message');
                return;
            }
            
            try {
                const formData = new FormData();
                formData.append('token', token);
                formData.append('ai_chat_key', 'ai:1'); // Assuming first AI chat has ID 1
                formData.append('message', message);
                formData.append('sender_type', 'user');
                
                const response = await fetch('send_ai_message.php', {
                    method: 'POST',
                    body: formData
                });
                
                const data = await response.json();
                document.getElementById('messageResult').innerHTML = '<pre>' + JSON.stringify(data, null, 2) + '</pre>';
            } catch (e) {
                document.getElementById('messageResult').innerHTML = 'Error: ' + e.message;
            }
        }
    </script>
</body>
</html>