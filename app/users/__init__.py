from flask import Blueprint

bp = Blueprint('users', __name__)

# Import routes at the bottom to avoid circular dependencies
from app.users import routes 