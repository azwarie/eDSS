<!-- FILE: C:\xampp\htdocs\dss\components\chatbot.php -->

<!-- Chatbot HTML Structure -->
<div class="chatbot-container">
    <div class="chatbot-header">
        <h2>ED Support Agent</h2>
        <span class="chat-toggle-btn">-</span>
    </div>
    <div class="chatbot-body">
        <div id="chat-window" class="chat-window">
            <!-- Messages will be injected here by JavaScript -->
        </div>
        <div id="typing-indicator" class="typing-indicator" style="display: none;">
            <div class="message bot-message">
                <span></span><span></span><span></span>
            </div>
        </div>
        <div class="chat-input-area">
            <input type="text" id="chat-input" placeholder="Ask a question...">
            <button id="send-btn">Send</button>
        </div>
    </div>
</div>

<!-- Chatbot CSS Styling -->
<style>
    .chatbot-container {
        position: fixed;
        bottom: 20px;
        right: 20px;
        width: 370px;
        max-width: 90vw;
        background-color: #fff;
        border-radius: 15px;
        box-shadow: 0 4px 20px rgba(0,0,0,0.15);
        overflow: hidden;
        display: flex;
        flex-direction: column;
        transition: all 0.3s ease-in-out;
        z-index: 1000;
        font-family: Arial, sans-serif;
    }
    .chatbot-header {
        background-color: #007bff;
        color: white;
        padding: 15px 20px;
        display: flex;
        justify-content: space-between;
        align-items: center;
        cursor: pointer;
    }
    .chatbot-header h2 { margin: 0; font-size: 1.1em; }

    /* --- MINIMIZE/TOGGLE BUTTON STYLING --- */
    .chat-toggle-btn {
        width: 24px;
        height: 24px;
        background-color: rgba(255,255,255,0.2);
        border-radius: 50%;
        display: flex;
        justify-content: center;
        align-items: center;
        font-size: 20px;
        font-weight: bold;
        line-height: 1;
        transition: transform 0.2s ease;
    }
    .chatbot-header:hover .chat-toggle-btn {
        transform: scale(1.1);
    }

    /* --- MINIMIZE BODY STYLING --- */
    .chatbot-body {
        display: flex;
        flex-direction: column;
        height: 450px;
        transition: height 0.3s ease-in-out, padding 0.3s ease-in-out; /* Smooth transition for height */
    }
    .chatbot-container.minimized .chatbot-body {
        height: 0;
        overflow: hidden; /* Hide content when minimized */
    }
    
    /* (Rest of your original CSS is unchanged) */
    .chat-window { flex-grow: 1; padding: 20px; overflow-y: auto; display: flex; flex-direction: column; gap: 12px; }
    .message { padding: 10px 15px; border-radius: 20px; max-width: 80%; line-height: 1.4; word-wrap: break-word; white-space: pre-wrap; }
    .user-message { background-color: #007bff; color: white; align-self: flex-end; border-bottom-right-radius: 5px; }
    .bot-message { background-color: #e9e9eb; color: #333; align-self: flex-start; border-bottom-left-radius: 5px; }
    .chat-input-area { display: flex; padding: 10px; border-top: 1px solid #ddd; }
    #chat-input { flex-grow: 1; border: 1px solid #ccc; border-radius: 20px; padding: 10px 15px; font-size: 1em; outline: none; }
    #send-btn { background-color: #007bff; color: white; border: none; padding: 0 20px; margin-left: 10px; border-radius: 20px; cursor: pointer; font-weight: bold; transition: background-color 0.2s; }
    .typing-indicator .message { padding: 15px; }
    .typing-indicator span { height: 8px; width: 8px; background-color: #999; border-radius: 50%; display: inline-block; animation: chatbot-bounce 1.3s infinite; }
    .typing-indicator span:nth-of-type(2) { animation-delay: 0.15s; }
    .typing-indicator span:nth-of-type(3) { animation-delay: 0.3s; }
    @keyframes chatbot-bounce { 0%, 60%, 100% { transform: translateY(0); } 30% { transform: translateY(-8px); } }
</style>

<!-- JavaScript Logic with Minimize/Maximize functionality -->
<script>
(function() {
    if (window.chatbotInitialized) return;
    window.chatbotInitialized = true;

    const chatContainer = document.querySelector(".chatbot-container"); // Target the main container
    const chatWindow = document.getElementById("chat-window");
    const chatInput = document.getElementById("chat-input");
    const sendBtn = document.getElementById("send-btn");
    const typingIndicator = document.getElementById("typing-indicator");
    const chatbotHeader = document.querySelector(".chatbot-header");
    const toggleBtn = document.querySelector(".chat-toggle-btn");

    // --- THE FINAL, AUTONOMOUS SYSTEM PROMPT (UNCHANGED) ---
    let conversationHistory = [
        { 
            role: "system", 
            content: "You are an autonomous data retrieval robot. Your ONLY job is to answer user questions by calling the provided tools. You MUST complete the entire plan without stopping to ask for permission or to state your intentions. If a tool requires an ID (like a `stay_id`) that you don't have, your first step is ALWAYS to silently call the tool that finds that ID (like `get_stay_id_from_patient_id`). Create a step-by-step plan internally and execute it until you have the final answer. DO NOT describe your plan to the user. DO NOT ask for permission. Just execute the plan and provide the final answer."
        }
    ];

    async function sendMessage() {
        const userMessageText = chatInput.value.trim();
        if (userMessageText === "") return;

        addMessageToChat("user", userMessageText);
        chatInput.value = "";
        showTypingIndicator();

        try {
            const response = await fetch('/dss/api/api.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ message: userMessageText, history: conversationHistory }),
            });

            if (!response.ok) throw new Error(`API Error: ${response.statusText}`);
            const data = await response.json();
            
            conversationHistory = data.history;
            addMessageToChat("bot", data.reply);

        } catch (error) {
            console.error("Chatbot Fetch Error:", error);
            addMessageToChat("bot", "An error occurred. Please check the console (F12).");
        } finally {
            hideTypingIndicator();
        }
    }
    
    function addMessageToChat(sender, text) {
        const messageElement = document.createElement("div");
        messageElement.classList.add("message", `${sender}-message`);
        messageElement.innerText = text;
        chatWindow.appendChild(messageElement);
        chatWindow.scrollTop = chatWindow.scrollHeight;
    }

    // --- FILLED IN FUNCTIONS ---
    function showTypingIndicator() {
        typingIndicator.style.display = 'block';
        chatWindow.scrollTop = chatWindow.scrollHeight;
    }
    function hideTypingIndicator() {
        typingIndicator.style.display = 'none';
    }

    sendBtn.addEventListener("click", sendMessage);
    chatInput.addEventListener("keyup", (event) => { if (event.key === "Enter") sendMessage(); });
    
    // --- MINIMIZE/MAXIMIZE LOGIC ---
    chatbotHeader.addEventListener("click", () => {
        // Toggle the 'minimized' class on the main container
        chatContainer.classList.toggle('minimized');
        
        // Update the button text based on the state
        if (chatContainer.classList.contains('minimized')) {
            toggleBtn.textContent = '+';
        } else {
            toggleBtn.textContent = '-';
        }
    });

    addMessageToChat("bot", "Hello! How can I assist you today?");
})();
</script>