# 🎓 Expert.Xchange: Peer-to-Peer Skill Sharing & Barter Platform

[![Python](https://img.shields.io/badge/Python-3.x-3776AB?logo=python&logoColor=white&style=for-the-badge)](https://python.org)
[![Flask](https://img.shields.io/badge/Flask-Backend%20API-000000?logo=flask&logoColor=white&style=for-the-badge)](https://flask.palletsprojects.com)
[![PHP](https://img.shields.io/badge/PHP-7.4%20%7C%208.x-777BB4?logo=php&logoColor=white&style=for-the-badge)](https://php.net)
[![MySQL](https://img.shields.io/badge/MySQL-Database-4479A1?logo=mysql&logoColor=white&style=for-the-badge)](https://mysql.com)
[![SQLAlchemy](https://img.shields.io/badge/SQLAlchemy-ORM-D12E2F?style=for-the-badge)](#)

**Expert.Xchange** is a dynamic, full-stack peer-to-peer knowledge-sharing platform that operates on a credit-based barter economy. By combining a high-performance **Python Flask REST API** backend with an interactive **PHP** web integration layer, Expert.Xchange enables users to list skills they offer, search for skills they wish to learn, initiate exchange requests, transfer credits, and rate their interactions.

---

## 🚀 Core Features

### 1. 💼 Skill Barter Marketplace
*   **Decentralized Matchmaking**: Users can list skills they are willing to teach (*Skills Offering*) and skills they want to acquire (*Skills Learning*).
*   **Unified Exchanges**: Initiating a request pairs an offered skill with a requested skill, creating a mutual exchange transaction.
*   **Proficiency Levels**: Tracks user experience (Beginner, Intermediate, Advanced, Expert) for precise skill pairings.

### 2. 🪙 Token Credit Economy
*   **Incentivized Sharing**: The system implements an internal token balance economy (`UserCredit` database table) to reward peer teaching.
*   **Balance Safety**: Enforces database integrity constraints (`balance >= 0` check constraint) to ensure users cannot spend credits they don't possess.
*   **Transaction Tracking**: Automatically logs transactions as `EARNED` or `SPENT` along with exact transaction descriptions.

### 3. 🛡️ Session Security & Authentication
*   **Dual Integration Security**: Implements secure session protection in both PHP and Python.
*   **Encryption**: Utilizes secure password hashing (`bcrypt` via `password_hash` in PHP and `pbkdf2` via `Werkzeug` in Python).
*   **Session Guarding**: PHP scripts configure secure HTTP-only cookies (`session.cookie_httponly = 1` and `session.use_only_cookies = 1`) to eliminate XSS session hijacking vulnerabilities.

### 4. ⭐ Trust & Rating Engine
*   **Community Reviews**: Users can submit 1-to-5 star reviews accompanied by text comments on completion of a skill exchange.
*   **Verification**: Enforces strict rating validation constraints directly on the database schema (`CHECK (stars >= 1 AND stars <= 5)`).

### 5. 🔔 Real-Time Notification Stream
*   **Active Updates**: Incorporates a lightweight server-sent notification stream (`notification_stream.php`) to notify users instantly.
*   **Trigger Types**: Notifies users in real-time on `EXCHANGE_REQUEST`, `REQUEST_ACCEPTED`, `REQUEST_DECLINED`, or `CREDITS_RECEIVED`.

---

## 🛠️ Hybrid Tech Stack & Libraries

| Layer | Technology | Primary Role |
| :--- | :--- | :--- |
| **API Backend** | **Flask (Python 3.x)** | Powering decoupled microservices and token transaction APIs |
| **Frontend UI** | **PHP / HTML5 / CSS3** | Dynamic page rendering, forms, and session integration |
| **Database ORM** | **Flask-SQLAlchemy** | Entity relations mapping & structural database queries |
| **Database** | **MySQL** | Relational data persistence, cascades, and check constraints |
| **Token Auth** | **Flask-JWT-Extended** | Stateless JWT protection for critical API endpoints |
| **DB Migrations** | **Flask-Migrate (Alembic)** | Smooth database schema updates and version control |

---

## 📁 System Architecture & Directory Structure

```text
expert_xchange/
├── app/                         # CORE PYTHON BACKEND (Flask App)
│   ├── auth/                    # JWT user registration and authentication endpoints
│   ├── users/                   # User profile management and credit inquiries
│   ├── skills/                  # Skill directory APIs (add, search, filter)
│   ├── exchanges/               # Matchmaking and exchange transaction services
│   ├── notifications/           # Event notification push handlers
│   ├── models.py                # Database entity mappings (User, Profile, Skill, etc.)
│   └── __init__.py              # Flask app factory, CORS, and blueprint registers
├── templates/                   # Frontend Page Layouts
│   ├── home.html                # Platform landing hub, forms, and login screens
│   ├── about.html               # Platform mission and collaborative workspace info
│   └── profile-setup.html       # Initial onboarding profile wizard
├── static/                      # Static Assets (Global styles, custom UI, scripts)
├── connect.php                  # PHP Core controller handling login/signup & contacts
├── edit-profile.php             # PHP user profile editor (skills updating)
├── profile.php                  # PHP active dashboard (viewing ratings & current credits)
├── notification_stream.php      # PHP real-time event broadcaster
├── handle_credit_transfer.php   # PHP API transaction executor
├── database.sql                 # MySQL raw relational database schema
├── run.py                       # Backend development server entry point
├── config.py / config.php       # Environment configuration files
└── requirements.txt             # Python backend dependencies manifest
```

---

## 🛠️ Installation & Getting Started

### 1. Database Setup
1.  Start your local MySQL server (XAMPP, WampServer, or native MySQL).
2.  Import the database schema using your terminal or phpMyAdmin:
    ```sql
    source database.sql;
    ```

### 2. Python Flask Backend Setup
1.  Navigate to the project root and create a virtual environment:
    ```bash
    python -m venv .venv
    source .venv/bin/activate  # On Windows: .venv\Scripts\activate
    ```
2.  Install required dependencies:
    ```bash
    pip install -r requirements.txt
    ```
3.  Launch the backend service:
    ```bash
    python run.py
    ```

### 3. PHP Frontend Deployment
1.  Copy the `expert_xchange` folder to your local server directory (e.g., `htdocs` for XAMPP or `www` for WAMP).
2.  Open your browser and navigate to:
    ```text
    http://localhost/expert_xchange/templates/home.html
    ```

---

## ✍️ Author & Maintainer

*   **Aaron Mohan** (GitHub: [@aaronmohan](https://github.com/aaronmohan))

*Designed and developed as a high-performance, secure skill barter system.*
