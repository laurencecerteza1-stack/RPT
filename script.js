function showSection(section) {
    const title = document.getElementById('section-title');

    if (section === 'home') {
        document.getElementById('home-view').style.display = 'block';
        title.innerText = "Home Dashboard";
    } 
    else if (section === 'liaison') {
        window.location.href = 'Liason.html';
    }
    else if (section === 'slli') {
        window.location.href = 'SLLI.html';
        
    }
    else if (section === 'slrdi') {
        window.location.href = 'SLRDI.html';
    }
    else if (section === 'released') {
        window.location.href = 'release.html';
    }
}