git add .
git commit -m "Subiendo mi proyecto desde xampp"
git branch -M main
if (git remote | Select-String "origin" -Quiet) {
    git remote set-url origin "https://github.com"
} else {
    git remote add origin "https://github.com"
}
git push -u origin main
