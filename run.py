from app import create_app, db
from app.models import User, UserProfile, Skill, UserRating, UserCredit, SkillExchangeRequest, Notification

app = create_app()

@app.shell_context_processor
def make_shell_context():
    return {
        'db': db,
        'User': User,
        'UserProfile': UserProfile,
        'Skill': Skill,
        'UserRating': UserRating,
        'UserCredit': UserCredit,
        'SkillExchangeRequest': SkillExchangeRequest,
        'Notification': Notification
    }

if __name__ == '__main__':
    app.run(debug=True) 