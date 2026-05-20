from flask import Blueprint

bp = Blueprint('exchanges', __name__)

# Import routes at the bottom
from app.exchanges import routes 