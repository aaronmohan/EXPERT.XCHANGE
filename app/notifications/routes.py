from flask import jsonify
from flask_jwt_extended import jwt_required, get_jwt_identity
from app import db
from app.models import Notification, User
from app.notifications import bp

# Get all notifications for the current user
@bp.route('', methods=['GET'])
@jwt_required()
def get_notifications():
    current_user_id = get_jwt_identity()
    notifications = Notification.query.filter_by(user_id=current_user_id).order_by(Notification.timestamp.desc()).all()
    return jsonify([{
        'id': notification.id,
        'message': notification.message,
        'is_read': notification.is_read,
        'timestamp': notification.timestamp.isoformat(),
        'related_url': notification.related_url
    } for notification in notifications]), 200

# Get only unread notifications
@bp.route('/unread', methods=['GET'])
@jwt_required()
def get_unread_notifications():
    current_user_id = get_jwt_identity()
    notifications = Notification.query.filter_by(user_id=current_user_id, is_read=False).order_by(Notification.timestamp.desc()).all()
    return jsonify([{
        'id': notification.id,
        'message': notification.message,
        'is_read': notification.is_read,
        'timestamp': notification.timestamp.isoformat(),
        'related_url': notification.related_url
    } for notification in notifications]), 200

# Mark a specific notification as read
@bp.route('/<int:notification_id>/read', methods=['PUT'])
@jwt_required()
def mark_notification_as_read(notification_id):
    current_user_id = get_jwt_identity()
    notification = Notification.query.get_or_404(notification_id)

    # Ensure the notification belongs to the current user
    if notification.user_id != current_user_id:
        return jsonify({"message": "Unauthorized"}), 403

    notification.is_read = True
    db.session.commit()
    return jsonify({"message": "Notification marked as read"}), 200

# Mark all unread notifications as read
@bp.route('/read-all', methods=['PUT'])
@jwt_required()
def mark_all_notifications_as_read():
    current_user_id = get_jwt_identity()
    unread_notifications = Notification.query.filter_by(user_id=current_user_id, is_read=False).all()

    for notification in unread_notifications:
        notification.is_read = True

    db.session.commit()
    return jsonify({"message": f"{len(unread_notifications)} notifications marked as read"}), 200

# Delete a notification
@bp.route('/<int:notification_id>', methods=['DELETE'])
@jwt_required()
def delete_notification(notification_id):
    current_user_id = get_jwt_identity()
    notification = Notification.query.get_or_404(notification_id)

    # Ensure the notification belongs to the current user
    if notification.user_id != current_user_id:
        return jsonify({"message": "Unauthorized"}), 403

    db.session.delete(notification)
    db.session.commit()
    return jsonify({"message": "Notification deleted successfully"}), 200 