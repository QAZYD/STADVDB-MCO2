const runBtn = document.getElementById("runBtn");
const logDiv = document.getElementById("log");

const { isMaster, actionUrl } = window.appConfig;

// Enable button if master server
if (isMaster) {
    runBtn.disabled = false;
} else {
    logDiv.textContent = "This server is not the master. You cannot run scripts.";
}

runBtn.addEventListener("click", function () {
    runBtn.disabled = true;

    // Start log with <pre> so newlines show correctly
    logDiv.innerHTML = "<pre>Starting scripts...\n</pre>";

    const xhr = new XMLHttpRequest();
    xhr.open("POST", actionUrl, true);
    xhr.setRequestHeader("Content-type", "application/x-www-form-urlencoded");

    xhr.onreadystatechange = function () {
        if (xhr.readyState === 4) {
            if (xhr.status === 200) {
                // Append response inside <pre>
                logDiv.innerHTML = `<pre>Starting scripts...\n${xhr.responseText}</pre>`;
                logDiv.scrollTop = logDiv.scrollHeight;
                runBtn.disabled = false;
            } else {
                logDiv.innerHTML += "\n❌ Error running scripts.";
                runBtn.disabled = false;
            }
        }
    };

    xhr.send("run=1");
});
