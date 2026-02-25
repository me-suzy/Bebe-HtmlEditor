# HTML Editor – Mini Dreamweaver în browser

Un editor HTML/PHP simplu, tip „mini Dreamweaver”, cu două panouri:
- **Cod**: editor bazat pe CodeMirror, cu evidențiere de sintaxă.
- **Design**: previzualizare live într-un iframe, cu zonă `contentEditable` pentru editare vizuală.

Modificările sunt sincronizate în ambele direcții:
- Schimbările din **Cod** se propagă în **Design**.
- Schimbările din **Design** (iframe) se propagă înapoi în **Cod**, cu suport pentru **Undo/Redo** pe ultimele mutări.

---

## Funcționalități principale

- **Panou Cod**:
  - Evidențiere de sintaxă pentru HTML/PHP (CodeMirror).
  - Undo/Redo, salvare fișier, încărcare fișiere dintr-un director rădăcină configurat.
  - Sincronizare selecție (unde e cursorul în cod).

- **Panou Design (WYSIWYG)**:
  - Iframe cu `contentEditable` activat pe `<body>`.
  - Scriere directă în paragraf / titluri, adăugare de text, spații etc.
  - Undo/Redo pe modificările făcute în Design, fără a pierde focusul.
  - Panou de proprietăți (jos) pentru:
    - font-family,
    - mărime font,
    - aliniere text,
    - stiluri simple.

- **Previzualizare corectă a CSS-ului**:
  - HTML-ul de preview se generează cu un `<base>` care permite încărcarea corectă a CSS-urilor și a resurselor (imagini, JS) din proiect.
  - Pentru fișiere PHP, aplicația execută direct scriptul (în contextul directorului fișierului).

---

## Tehnologii folosite

- **Backend**: PHP simplu (un singur fișier `index.php`), fără framework-uri.
- **Frontend**:
  - HTML, CSS, JavaScript vanilla.
  - CodeMirror pentru editorul de cod.
- **Altele**:
  - Sistem propriu pentru „asset proxy” (servește CSS/JS/imagini din sistemul de fișiere).
  - Logica custom pentru Undo/Redo sincronizat între Cod și Design.

---

## Cum se pornește aplicația

1. **Configurare rădăcină proiect**  
   În `index.php`, variabila `$ROOT` setează directorul de lucru (proiectul ale cărui fișiere vrei să le editezi).

2. **Pornire server PHP local**  
   Din acest folder, rulează:

   ```bash
   php -S localhost:8000
   ```

   Apoi deschide în browser:

   ```text
   http://localhost:8000/index.php
   ```

3. **Editare fișiere**  
   - Navighezi în listă, alegi fișierul HTML/PHP.
   - Editezi fie în panoul **Cod**, fie în panoul **Design**:
     - În Design, tastezi direct în paragraf, adaugi cuvinte, spații etc.
     - Apasă **Undo/Redo** (Ctrl+Z / Ctrl+Y) în Design pentru a anula/reaplica ultimele mutări.

4. **Salvare**  
   - Ctrl+S sau butonul „Salvează” salvează modificările în fișierul de pe disc.
   - Previzualizarea se actualizează cu conținutul curent.

---

## Capturi de ecran

În acest folder sunt incluse 2 capturi de ecran ale aplicației (fișierele `.png` pe care le-ai salvat).
Le poți păstra în repo pentru a ilustra modul de funcționare al editorului.

---

