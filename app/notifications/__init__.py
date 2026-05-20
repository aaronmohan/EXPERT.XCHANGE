from flask import Blueprint

bp = Blueprint('notifications', __name__)

# Import routes at the bottom
from app.notifications import routes 