from flask import Blueprint

bp = Blueprint('skills', __name__)

# Import routes at the bottom
from app.skills import routes 