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
    logDiv.textContent = "Starting scripts...\n";

    const xhr = new XMLHttpRequest();
    xhr.open("POST", actionUrl, true);
    xhr.setRequestHeader("Content-type", "application/x-www-form-urlencoded");

    xhr.onreadystatechange = function () {
        if (xhr.readyState === 4) {
            if (xhr.status === 200) {
                logDiv.textContent += xhr.responseText;
                logDiv.scrollTop = logDiv.scrollHeight;
                runBtn.disabled = false;
            } else {
                logDiv.textContent += "\n❌ Error running scripts.";
                runBtn.disabled = false;
            }
        }
    };

    xhr.send("run=1");
});
