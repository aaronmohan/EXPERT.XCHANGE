from datetime import datetime, timezone
from werkzeug.security import generate_password_hash, check_password_hash
from app import db

# Association table for user skills
user_skills = db.Table('user_skills',
    db.Column('user_id', db.Integer, db.ForeignKey('user.id'), primary_key=True),
    db.Column('skill_id', db.Integer, db.ForeignKey('skill.id'), primary_key=True)
)

class User(db.Model):
    id = db.Column(db.Integer, primary_key=True)
    username = db.Column(db.String(64), index=True, unique=True, nullable=False)
    email = db.Column(db.String(120), index=True, unique=True, nullable=False)
    password_hash = db.Column(db.String(256))
    registration_date = db.Column(db.DateTime, default=lambda: datetime.now(timezone.utc))

    profile = db.relationship('UserProfile', backref='user', uselist=False, lazy=True, cascade="all, delete-orphan")
    credits = db.relationship('UserCredit', backref='user', uselist=False, lazy=True, cascade="all, delete-orphan")
    ratings_given = db.relationship('UserRating', foreign_keys='UserRating.rater_id', backref='rater', lazy='dynamic', cascade="all, delete-orphan")
    ratings_received = db.relationship('UserRating', foreign_keys='UserRating.rated_user_id', backref='rated_user', lazy='dynamic', cascade="all, delete-orphan")
    skills = db.relationship('Skill', secondary=user_skills, lazy='subquery',
                             backref=db.backref('users', lazy=True))
    sent_requests = db.relationship('SkillExchangeRequest', foreign_keys='SkillExchangeRequest.requester_id', backref='requester', lazy='dynamic', cascade="all, delete-orphan")
    received_requests = db.relationship('SkillExchangeRequest', foreign_keys='SkillExchangeRequest.requested_user_id', backref='requested_user', lazy='dynamic', cascade="all, delete-orphan")
    notifications = db.relationship('Notification', backref='user', lazy='dynamic', cascade="all, delete-orphan")

    def set_password(self, password):
        self.password_hash = generate_password_hash(password)

    def check_password(self, password):
        return check_password_hash(self.password_hash, password)

    def __repr__(self):
        return f'<User {self.username}>'

class UserProfile(db.Model):
    id = db.Column(db.Integer, primary_key=True)
    user_id = db.Column(db.Integer, db.ForeignKey('user.id'), unique=True, nullable=False)
    first_name = db.Column(db.String(64))
    last_name = db.Column(db.String(64))
    bio = db.Column(db.Text)
    location = db.Column(db.String(120))
    profile_picture_url = db.Column(db.String(256)) # URL to profile picture
    last_updated = db.Column(db.DateTime, default=lambda: datetime.now(timezone.utc), onupdate=lambda: datetime.now(timezone.utc))

    def __repr__(self):
        return f'<UserProfile for User {self.user_id}>'

class Skill(db.Model):
    id = db.Column(db.Integer, primary_key=True)
    name = db.Column(db.String(100), index=True, unique=True, nullable=False)
    description = db.Column(db.Text)
    category = db.Column(db.String(100), index=True)
    # Consider adding proficiency level if skills are tied to users directly
    # proficiency_level = db.Column(db.String(50)) # e.g., Beginner, Intermediate, Advanced, Expert

    def __repr__(self):
        return f'<Skill {self.name}>'

class UserRating(db.Model):
    id = db.Column(db.Integer, primary_key=True)
    rater_id = db.Column(db.Integer, db.ForeignKey('user.id'), nullable=False)
    rated_user_id = db.Column(db.Integer, db.ForeignKey('user.id'), nullable=False)
    rating = db.Column(db.Integer, nullable=False) # e.g., 1-5 stars
    comment = db.Column(db.Text)
    timestamp = db.Column(db.DateTime, index=True, default=lambda: datetime.now(timezone.utc))
    exchange_request_id = db.Column(db.Integer, db.ForeignKey('skill_exchange_request.id')) # Link rating to a specific exchange

    __table_args__ = (db.CheckConstraint('rating >= 1 AND rating <= 5', name='rating_check'),)

    def __repr__(self):
        return f'<UserRating {self.rater_id} -> {self.rated_user_id}: {self.rating}>'

class UserCredit(db.Model):
    id = db.Column(db.Integer, primary_key=True)
    user_id = db.Column(db.Integer, db.ForeignKey('user.id'), unique=True, nullable=False)
    balance = db.Column(db.Integer, default=0, nullable=False)
    last_updated = db.Column(db.DateTime, default=lambda: datetime.now(timezone.utc), onupdate=lambda: datetime.now(timezone.utc))

    __table_args__ = (db.CheckConstraint('balance >= 0', name='credit_balance_check'),)

    def __repr__(self):
        return f'<UserCredit User {self.user_id}: {self.balance}>'

class SkillExchangeRequest(db.Model):
    id = db.Column(db.Integer, primary_key=True)
    requester_id = db.Column(db.Integer, db.ForeignKey('user.id'), nullable=False)
    requested_user_id = db.Column(db.Integer, db.ForeignKey('user.id'), nullable=False)
    offered_skill_id = db.Column(db.Integer, db.ForeignKey('skill.id'), nullable=False)
    requested_skill_id = db.Column(db.Integer, db.ForeignKey('skill.id'), nullable=False)
    status = db.Column(db.String(50), default='pending', index=True, nullable=False) # e.g., pending, accepted, rejected, completed, cancelled
    message = db.Column(db.Text)
    request_timestamp = db.Column(db.DateTime, index=True, default=lambda: datetime.now(timezone.utc))
    response_timestamp = db.Column(db.DateTime, index=True, nullable=True)
    proposed_datetime = db.Column(db.DateTime, nullable=True) # Optional: For scheduling

    offered_skill = db.relationship('Skill', foreign_keys=[offered_skill_id])
    requested_skill = db.relationship('Skill', foreign_keys=[requested_skill_id])
    rating = db.relationship('UserRating', backref='exchange_request', uselist=False, lazy=True) # Link to rating if applicable

    def __repr__(self):
        return f'<SkillExchangeRequest {self.id}: {self.requester_id} -> {self.requested_user_id} ({self.status})>'

class Notification(db.Model):
    id = db.Column(db.Integer, primary_key=True)
    user_id = db.Column(db.Integer, db.ForeignKey('user.id'), nullable=False)
    message = db.Column(db.String(255), nullable=False)
    is_read = db.Column(db.Boolean, default=False, nullable=False)
    timestamp = db.Column(db.DateTime, index=True, default=lambda: datetime.now(timezone.utc))
    related_url = db.Column(db.String(255), nullable=True) # e.g., URL to the related exchange request

    def __repr__(self):
        return f'<Notification {self.id} for User {self.user_id}>' 