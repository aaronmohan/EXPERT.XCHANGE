from flask import Flask, render_template
from flask_sqlalchemy import SQLAlchemy
from flask_migrate import Migrate
from flask_cors import CORS
from flask_jwt_extended import JWTManager
from config import Config
import os

db = SQLAlchemy()
migrate = Migrate()
jwt = JWTManager()
cors = CORS()

def create_app(config_class=Config):
    app = Flask(
        __name__,
        static_folder='../static',      # Relative path from 'app' folder to 'static'
        template_folder='../templates'  # Relative path from 'app' folder to 'templates'
    )
    app.config.from_object(config_class)

    db.init_app(app)
    migrate.init_app(app, db)
    jwt.init_app(app)
    cors.init_app(app, resources={r"/api/*": {"origins": "*"}}) # Allow all origins for API routes

    # Register blueprints here
    from app.auth import bp as auth_bp
    app.register_blueprint(auth_bp, url_prefix='/api/auth')

    from app.users import bp as users_bp
    app.register_blueprint(users_bp, url_prefix='/api/users')

    from app.skills import bp as skills_bp
    app.register_blueprint(skills_bp, url_prefix='/api/skills')

    from app.exchanges import bp as exchanges_bp
    app.register_blueprint(exchanges_bp, url_prefix='/api/exchanges')

    from app.notifications import bp as notifications_bp
    app.register_blueprint(notifications_bp, url_prefix='/api/notifications')

    # You might want a simple root route for testing
    @app.route('/')
    def index():
        # return "Welcome to the Expert Xchange API!" # Keep API root separate or remove
        return render_template('home.html') # Render home page at root

    # Optionally add a specific route for home too
    @app.route('/home')
    def home():
        return render_template('home.html')
    
    # Add routes for other static pages if needed
    @app.route('/about')
    def about():
        return render_template('about.html')

    # Placeholder for profile page route mentioned in JS
    @app.route('/profile')
    # @jwt_required() # Protect this route later
    def profile_page():
        # We'll need to fetch user data using the token and pass it to a template
        # For now, just render a placeholder or the profile setup
        # return render_template('profile.html') # If you create a profile template
        return render_template('profile-setup.html') # Or use the setup page for now

    return app

# Import models at the bottom to avoid circular imports
# from app import models # <-- REMOVED THIS LINE 