// Obfuscated email contact function
function sendEmail(localPart, domain) {
  const emailAddress = localPart + '@' + domain;
  window.location.href = 'mailto:' + emailAddress;
}

// Copy email to clipboard function
function copyEmail(localPart, domain) {
  const emailAddress = localPart + '@' + domain;
  navigator.clipboard.writeText(emailAddress).then(() => {
    alert('Email address copied to clipboard: ' + emailAddress);
  }).catch(() => {
    // Fallback if clipboard API fails
    const temp = document.createElement('textarea');
    temp.value = emailAddress;
    document.body.appendChild(temp);
    temp.select();
    document.execCommand('copy');
    document.body.removeChild(temp);
    alert('Email address copied to clipboard: ' + emailAddress);
  });
}
